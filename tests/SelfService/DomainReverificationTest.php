<?php

declare(strict_types=1);

/**
 * Custom domains proved again and again (ADR 0198): a domain in service is
 * re-checked on a schedule against the same TXT record that first proved
 * it, goes failing at the first check that finds the record gone -- still
 * served -- and lapses past the grace, when its host answers as unknown.
 * A later proof restores it; a lapsed claim is released after a while; and
 * another tenant takes the host only by proving it with its own token.
 * Every change is recorded and announced, and every boundary is to the
 * second, with DNS the test's.
 */

namespace Tinvar\Tests\Tenancy\SelfService\Reverification;

use Carbon\CarbonImmutable;
use Tinvar\Attributes\Schedule;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tenancy\SelfService\DomainLapse;
use Tinvar\Tenancy\SelfService\ReverifyTenantDomains;
use Tinvar\Tenancy\SelfService\TenantChange;
use Tinvar\Tenancy\SelfService\TenantDomainChanged;
use Tinvar\Tenancy\SelfService\TenantDomainStatus;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdminWorld;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/databases.php';
require_once __DIR__ . '/Fixtures/self_service.php';

const WEEK = 604_800;

/** Adds $host for $tenant, publishes its records and checks it into service; its id. */
function verified(AdminWorld $world, string $host = 'shop.acme.com', string $tenant = 'acme', string $as = 'm-1'): string
{
    $world->act('OrganizationDomains.add', ['host' => $host], $as, "{$tenant}.example.test");
    $domain = array_values(array_filter($world->domains->forTenant($tenant), static fn ($d): bool => $d->host === $host))[0];
    $world->dns->prove($host, $domain->token);
    $response = $world->act('OrganizationDomains.check', ['domain' => $domain->id], $as, "{$tenant}.example.test");
    expect($response->status)->toBe(200, $response->body())
        ->and($world->domains->tenantForHost($host))->toBe($tenant);

    return $domain->id;
}

/** The scheduled task, run now. */
function run(AdminWorld $world): void
{
    new ReverifyTenantDomains()($world->kit->reverification ?? throw new \LogicException('No custom domains.'));
}

function at(AdminWorld $world, string $tenant, string $id): \Tinvar\Tenancy\SelfService\TenantDomain
{
    return $world->domains->ofTenant($tenant, $id) ?? throw new \LogicException("No domain {$id}.");
}

/** @return list<string> each announced change, as `tenant:host:from>to`; the first proofs only when $proofs */
function announced(AdminWorld $world, bool $proofs = false): array
{
    return array_values(array_filter(
        array_map(
            static fn (TenantDomainChanged $e): string => "{$e->tenant}:{$e->host}:{$e->from->value}>{$e->to->value}",
            $world->events->dispatched(TenantDomainChanged::class),
        ),
        static fn (string $change): bool => $proofs || !str_ends_with($change, ':pending>verified'),
    ));
}

function later(string $modify): void
{
    AdminWorld::$now = AdminWorld::$now->modify($modify);
}

describe('the scheduled task', function () {
    it('is scheduled, runs as nobody across every tenant, and books each next check a day out at most, spread', function () {
        $world = new AdminWorld();
        verified($world);
        verified($world, 'brand.example.org', 'globex', 'm-9');
        later('+1 day');
        $asked = \count($world->dns->asked);

        run($world);
        $acme = at($world, 'acme', $world->domains->forTenant('acme')[0]->id);
        $globex = at($world, 'globex', $world->domains->forTenant('globex')[0]->id);

        expect(new \ReflectionClass(ReverifyTenantDomains::class)->getAttributes(Schedule::class))->not->toBe([])
            ->and(\count($world->dns->asked))->toBeGreaterThan($asked)
            ->and($acme->checkedAt?->equalTo(AdminWorld::$now))->toBeTrue()
            ->and($acme->status)->toBe(TenantDomainStatus::Verified)
            ->and($acme->nextCheckAt?->greaterThan(AdminWorld::$now->addHours(20)))->toBeTrue()
            ->and($acme->nextCheckAt?->lessThanOrEqualTo(AdminWorld::$now->addDay()))->toBeTrue()
            ->and($globex->checkedAt?->equalTo(AdminWorld::$now))->toBeTrue()
            ->and(announced($world))->toBe([]);
    });

    it('checks only what is due', function () {
        $world = new AdminWorld();
        verified($world);
        later('+1 day');
        run($world);
        $asked = \count($world->dns->asked);

        later('+1 hour');
        run($world);

        expect(\count($world->dns->asked))->toBe($asked);
    });

    it('checks a domain from before the re-verification at the first run, and gives one whose record is gone a notice first', function () {
        $world = new AdminWorld();
        $id = verified($world);
        $world->orm->statements()->execute('UPDATE tenant_domains SET next_check_at = NULL');
        $world->dns->texts = [];

        run($world);
        $first = AdminWorld::$now;
        $domain = at($world, 'acme', $id);
        $page = $world->page('/settings/domains')['domains'][0];
        $claimed = $world->act('OrganizationDomains.add', ['host' => 'shop.acme.com'], 'm-9', 'globex.example.test');

        // Through the notice and the grace after it, still served.
        AdminWorld::$now = $first->addDays(14)->addSeconds(WEEK);
        run($world);
        $served = $world->domains->tenantForHost('shop.acme.com');
        AdminWorld::$now = $first->addDays(14)->addSeconds(WEEK + 1);
        run($world);

        expect($domain->status)->toBe(TenantDomainStatus::Failing)
            ->and($domain->unprovedSince?->equalTo($first->addDays(14)))->toBeTrue()
            ->and($domain->reason)->toContain('Keep')
            ->and($page['lapsesAt'])->toBe($first->addDays(14)->addSeconds(WEEK)->toJSON())
            ->and(announced($world))->toBe(['acme:shop.acme.com:verified>failing', 'acme:shop.acme.com:failing>deactivated'])
            // Nobody may claim it during the notice.
            ->and($claimed->status)->toBe(422)
            ->and($served)->toBe('acme')
            ->and($world->domains->tenantForHost('shop.acme.com'))->toBeNull();
    });
});

describe('a domain whose record goes', function () {
    it('goes failing at the first check that finds it missing, and is still served', function () {
        $world = new AdminWorld();
        $id = verified($world);
        $world->dns->texts = [];
        later('+1 day');

        run($world);
        $domain = at($world, 'acme', $id);
        $page = $world->page('/settings/domains')['domains'][0];

        expect($domain->status)->toBe(TenantDomainStatus::Failing)
            ->and($domain->unprovedSince?->equalTo(AdminWorld::$now))->toBeTrue()
            ->and($domain->reason)->toContain('_tinvar-challenge.shop.acme.com')
            ->and($world->domains->tenantForHost('shop.acme.com'))->toBe('acme')
            ->and($world->kit->realms->forHost('shop.acme.com')?->tenant)->toBe('acme')
            ->and($page['status'])->toBe('failing')
            ->and($page['lapsesAt'])->toBe(AdminWorld::$now->addSeconds(WEEK)->toJSON())
            ->and($world->activity->changes())->toContain('tenant.domain_failing')
            ->and(announced($world))->toBe(['acme:shop.acme.com:verified>failing']);
    });

    it('counts a record carrying another token as missing', function () {
        $world = new AdminWorld();
        $id = verified($world);
        $world->dns->texts['_tinvar-challenge.shop.acme.com'] = ['tinvar-domain=' . str_repeat('cd', 16)];
        later('+1 day');

        run($world);

        expect(at($world, 'acme', $id)->status)->toBe(TenantDomainStatus::Failing);
    });

    it('does not count a lookup that failed toward the missing record\'s grace, nor end its run', function () {
        $world = new AdminWorld();
        $id = verified($world);
        $world->dns->texts = [];
        later('+1 day');
        run($world);
        $since = at($world, 'acme', $id)->unprovedSince;

        $world->dns->failing['_tinvar-challenge.shop.acme.com'] = true;
        later('+1 hour');
        run($world);
        $unread = at($world, 'acme', $id);

        expect($since)->not->toBeNull()
            ->and($unread->status)->toBe(TenantDomainStatus::Failing)
            ->and($unread->unprovedSince?->equalTo($since))->toBeTrue()
            ->and($unread->unreadableSince)->not->toBeNull();
    });

    it('fails a domain whose record cannot be read, on a clock of its own, and a readable answer ends it', function () {
        $world = new AdminWorld();
        $id = verified($world);
        $world->dns->failing['_tinvar-challenge.shop.acme.com'] = true;
        later('+1 day');
        run($world);
        $unread = at($world, 'acme', $id);

        unset($world->dns->failing['_tinvar-challenge.shop.acme.com']);
        later('+1 hour');
        run($world);
        $read = at($world, 'acme', $id);

        expect($unread->status)->toBe(TenantDomainStatus::Failing)
            ->and($unread->unprovedSince)->toBeNull()
            ->and($unread->unreadableSince?->equalTo(AdminWorld::$now->subHour()))->toBeTrue()
            ->and($unread->reason)->toContain('could not be read')
            ->and($read->status)->toBe(TenantDomainStatus::Verified)
            ->and($read->unreadableSince)->toBeNull();
    });

    it('lapses a domain whose record cannot be read past the grace, to the second: a new owner cannot keep it served by making its lookups fail', function () {
        $world = new AdminWorld();
        $id = verified($world);
        // The name changes hands; its new owner leaves the CNAME here and
        // delegates _tinvar-challenge to a nameserver that answers SERVFAIL.
        $world->dns->texts = [];
        $world->dns->failing['_tinvar-challenge.shop.acme.com'] = true;
        later('+1 day');
        run($world);
        $first = AdminWorld::$now;

        AdminWorld::$now = $first->addSeconds(3600);
        run($world);
        $atGrace = at($world, 'acme', $id);
        AdminWorld::$now = $first->addSeconds(3601);
        run($world);

        expect($atGrace->status)->toBe(TenantDomainStatus::Failing)
            ->and(at($world, 'acme', $id)->status)->toBe(TenantDomainStatus::Deactivated)
            ->and(at($world, 'acme', $id)->lapse)->toBe(DomainLapse::Ownership)
            ->and(at($world, 'acme', $id)->reason)->toContain('could not be read')
            ->and($world->domains->tenantForHost('shop.acme.com'))->toBeNull();
    });

    it('never stays served through months of failed lookups', function () {
        $world = new AdminWorld();
        verified($world);
        $world->dns->texts = [];
        $world->dns->failing['_tinvar-challenge.shop.acme.com'] = true;
        for ($i = 0; $i < 120; $i++) {
            later('+1 day');
            run($world);
        }

        expect($world->domains->tenantForHost('shop.acme.com'))->toBeNull();
    });

    it('is restored by the next check that finds it again', function () {
        $world = new AdminWorld();
        $id = verified($world);
        $token = at($world, 'acme', $id)->token;
        $world->dns->texts = [];
        later('+1 day');
        run($world);

        $world->dns->prove('shop.acme.com', $token);
        later('+2 hours');
        run($world);
        $domain = at($world, 'acme', $id);

        expect($domain->status)->toBe(TenantDomainStatus::Verified)
            ->and($domain->unprovedSince)->toBeNull()
            ->and($world->activity->changes())->toContain('tenant.domain_restored')
            ->and(announced($world))->toBe(['acme:shop.acme.com:verified>failing', 'acme:shop.acme.com:failing>verified']);
    });

    it('re-checks a failing domain within the hour, not the day', function () {
        $world = new AdminWorld();
        $id = verified($world);
        $world->dns->texts = [];
        later('+1 day');
        run($world);

        expect(at($world, 'acme', $id)->nextCheckAt?->lessThanOrEqualTo(AdminWorld::$now->addHour()))->toBeTrue();
    });

    it('stays failing to the grace\'s last second, and lapses at the first check after it', function () {
        $world = new AdminWorld();
        $id = verified($world);
        $world->remembered->remember('m-1', 'epoch', 'acme', 'customer', 'https://shop.acme.com', AdminWorld::$now->getTimestamp());
        $world->remembered->remember('m-1', 'epoch', 'acme', 'customer', 'https://acme.example.test', AdminWorld::$now->getTimestamp());
        $world->dns->texts = [];
        later('+1 day');
        run($world);
        $first = AdminWorld::$now;

        AdminWorld::$now = $first->addSeconds(WEEK);
        run($world);
        $atGrace = at($world, 'acme', $id);

        AdminWorld::$now = $first->addSeconds(WEEK + 1);
        run($world);
        $lapsed = at($world, 'acme', $id);

        expect($atGrace->status)->toBe(TenantDomainStatus::Failing)
            ->and($lapsed->status)->toBe(TenantDomainStatus::Deactivated)
            ->and($lapsed->lapse)->toBe(DomainLapse::Ownership)
            ->and($lapsed->deactivatedAt?->equalTo(AdminWorld::$now))->toBeTrue()
            // Fail closed, at once: no lookup answers for the host.
            ->and($world->domains->tenantForHost('shop.acme.com'))->toBeNull()
            ->and($world->domains->customDomain('shop.acme.com', static fn (string $t): string => $t))->toBeNull()
            ->and($world->domains->realm('shop.acme.com', 'customer'))->toBeNull()
            ->and($world->domains->primaryFor('acme'))->toBeNull()
            ->and($world->kit->realms->forHost('shop.acme.com'))->toBeNull()
            ->and($world->remembered->forUser('m-1', 'epoch', AdminWorld::$now->getTimestamp()))->toHaveCount(1)
            ->and($world->page('/settings/domains')['domains'][0]['lapse'])->toBe('ownership')
            ->and($world->activity->changes())->toContain('tenant.domain_lapsed')
            ->and(announced($world))->toBe(['acme:shop.acme.com:verified>failing', 'acme:shop.acme.com:failing>deactivated']);
    });

    it('lapses at once when its DNS points elsewhere', function () {
        $world = new AdminWorld();
        $id = verified($world);
        $world->dns->aliases['shop.acme.com'] = 'elsewhere.example.net';
        $world->dns->addresses['elsewhere.example.net'] = ['198.51.100.9'];
        later('+1 day');

        run($world);

        expect(at($world, 'acme', $id)->status)->toBe(TenantDomainStatus::Deactivated)
            ->and(at($world, 'acme', $id)->lapse)->toBe(DomainLapse::Pointing)
            ->and($world->domains->tenantForHost('shop.acme.com'))->toBeNull()
            ->and(announced($world))->toBe(['acme:shop.acme.com:verified>deactivated']);
    });

    it('is not cleared by Caddy\'s ask, which checks where it points and not who owns it', function () {
        $world = new AdminWorld();
        $id = verified($world);
        $world->dns->texts = [];
        later('+1 day');
        run($world);

        later('+1 minute');
        $world->kit->verification?->verify(at($world, 'acme', $id)->custom());

        expect(at($world, 'acme', $id)->status)->toBe(TenantDomainStatus::Failing)
            ->and(at($world, 'acme', $id)->unprovedSince)->not->toBeNull();
    });
});

describe('a lapsed domain', function () {
    /** Lapses acme's shop.acme.com by its record; its id. */
    function lapsed(AdminWorld $world): string
    {
        $id = verified($world);
        $world->dns->texts = [];
        later('+1 day');
        run($world);
        later('+8 days');
        run($world);
        expect(at($world, 'acme', $id)->status)->toBe(TenantDomainStatus::Deactivated);

        return $id;
    }

    it('is restored by the schedule once its record is back, until its claim is released', function () {
        $world = new AdminWorld();
        $id = lapsed($world);
        $world->dns->prove('shop.acme.com', at($world, 'acme', $id)->token);
        later('+1 day');

        run($world);

        expect(at($world, 'acme', $id)->status)->toBe(TenantDomainStatus::Verified)
            ->and(at($world, 'acme', $id)->lapse)->toBeNull()
            ->and($world->domains->tenantForHost('shop.acme.com'))->toBe('acme')
            ->and($world->activity->changes())->toContain('tenant.domain_restored');
    });

    it('is released 30 days after it lapsed: pending, with a fresh token the old record does not prove', function () {
        $world = new AdminWorld();
        $id = lapsed($world);
        $lapsedAt = at($world, 'acme', $id)->deactivatedAt;
        $old = at($world, 'acme', $id)->token;

        AdminWorld::$now = $lapsedAt->addDays(30);
        run($world);
        $kept = at($world, 'acme', $id);

        AdminWorld::$now = $lapsedAt->addDays(30)->addSecond();
        run($world);
        $released = at($world, 'acme', $id);

        $world->dns->prove('shop.acme.com', $old);
        later('+1 day');
        run($world);

        expect($kept->status)->toBe(TenantDomainStatus::Deactivated)
            ->and($released->status)->toBe(TenantDomainStatus::Pending)
            ->and($released->token)->not->toBe($old)
            ->and($released->lapse)->toBeNull()
            ->and($released->nextCheckAt)->toBeNull()
            ->and(at($world, 'acme', $id)->status)->toBe(TenantDomainStatus::Pending)
            ->and($world->activity->changes())->toContain('tenant.domain_released')
            ->and(announced($world))->toContain('acme:shop.acme.com:deactivated>pending');
    });

    it('is released by "check now" past its release, rather than restored by its old record', function () {
        $world = new AdminWorld();
        $id = lapsed($world);
        $old = at($world, 'acme', $id)->token;
        $world->dns->prove('shop.acme.com', $old);

        AdminWorld::$now = (at($world, 'acme', $id)->deactivatedAt ?? AdminWorld::$now)->addDays(30)->addSecond();
        $world->act('OrganizationDomains.check', ['domain' => $id]);
        $domain = at($world, 'acme', $id);

        expect($domain->status)->toBe(TenantDomainStatus::Pending)
            ->and($domain->token)->not->toBe($old)
            ->and($world->domains->tenantForHost('shop.acme.com'))->toBeNull()
            ->and($world->activity->changes())->toContain('tenant.domain_released');
    });

    it('is another tenant\'s only once that tenant proves it with its own token', function () {
        $world = new AdminWorld();
        $id = lapsed($world);
        $world->dns->prove('shop.acme.com', at($world, 'acme', $id)->token);
        $world->act('OrganizationDomains.add', ['host' => 'shop.acme.com'], 'm-9', 'globex.example.test');
        $globex = $world->domains->forTenant('globex')[0];

        // acme's record proves nothing of globex's claim.
        later('+2 minutes');
        $world->act('OrganizationDomains.check', ['domain' => $globex->id], 'm-9', 'globex.example.test');
        $refused = at($world, 'globex', $globex->id)->status;

        $world->dns->prove('shop.acme.com', $globex->token);
        $world->dns->texts['_tinvar-challenge.shop.acme.com'] = ['tinvar-domain=' . $globex->token];
        later('+2 minutes');
        $world->act('OrganizationDomains.check', ['domain' => $globex->id], 'm-9', 'globex.example.test');

        expect($refused)->toBe(TenantDomainStatus::Pending)
            ->and(at($world, 'globex', $globex->id)->status)->toBe(TenantDomainStatus::Verified)
            ->and($world->domains->tenantForHost('shop.acme.com'))->toBe('globex');
    });
});

describe('a host another tenant holds', function () {
    it('cannot be claimed while its holder\'s record is there', function () {
        $world = new AdminWorld();
        verified($world);

        $response = $world->act('OrganizationDomains.add', ['host' => 'shop.acme.com'], 'm-9', 'globex.example.test');

        expect($response->status)->toBe(422)
            ->and($world->domains->forTenant('globex'))->toBe([]);
    });

    it('is handed over at once to a tenant that proves it while its holder\'s record is gone, without waiting out the grace', function () {
        $world = new AdminWorld();
        $acme = verified($world);
        $world->dns->texts = [];
        later('+1 day');
        run($world);

        $world->act('OrganizationDomains.add', ['host' => 'shop.acme.com'], 'm-9', 'globex.example.test');
        $globex = $world->domains->forTenant('globex')[0];
        // The name changed hands: its DNS now carries globex's token alone.
        $world->dns->texts['_tinvar-challenge.shop.acme.com'] = ['tinvar-domain=' . $globex->token];
        later('+2 minutes');
        $world->act('OrganizationDomains.check', ['domain' => $globex->id], 'm-9', 'globex.example.test');

        expect($world->domains->tenantForHost('shop.acme.com'))->toBe('globex')
            ->and(at($world, 'acme', $acme)->status)->toBe(TenantDomainStatus::Deactivated)
            ->and(at($world, 'acme', $acme)->lapse)->toBe(DomainLapse::Claimed)
            ->and(announced($world, proofs: true))->toBe([
                'acme:shop.acme.com:pending>verified',
                'acme:shop.acme.com:verified>failing',
                'acme:shop.acme.com:failing>deactivated',
                'globex:shop.acme.com:pending>verified',
            ]);
    });

    it('is never handed over once its holder\'s record is back', function () {
        $world = new AdminWorld();
        $acme = verified($world);
        $token = at($world, 'acme', $acme)->token;
        $world->dns->texts = [];
        later('+1 day');
        run($world);
        $world->act('OrganizationDomains.add', ['host' => 'shop.acme.com'], 'm-9', 'globex.example.test');
        $globex = $world->domains->forTenant('globex')[0];

        $world->dns->texts['_tinvar-challenge.shop.acme.com'] = ['tinvar-domain=' . $token, 'tinvar-domain=' . $globex->token];
        later('+2 minutes');
        $world->act('OrganizationDomains.check', ['domain' => $globex->id], 'm-9', 'globex.example.test');

        expect($world->domains->tenantForHost('shop.acme.com'))->toBe('acme')
            ->and(at($world, 'acme', $acme)->status)->toBe(TenantDomainStatus::Failing)
            ->and(at($world, 'globex', $globex->id)->status)->toBe(TenantDomainStatus::Pending)
            ->and(at($world, 'globex', $globex->id)->reason)->toContain('Another organization');
    });

    it('is never handed over while its holder\'s record cannot be read', function () {
        $world = new AdminWorld();
        $acme = verified($world);
        $world->dns->texts = [];
        later('+1 day');
        run($world);
        $world->act('OrganizationDomains.add', ['host' => 'shop.acme.com'], 'm-9', 'globex.example.test');
        $globex = $world->domains->forTenant('globex')[0];
        $world->dns->texts['_tinvar-challenge.shop.acme.com'] = ['tinvar-domain=' . $globex->token];

        // globex's proof reads, then the holder's lookup fails.
        $world->dns->failAfter['_tinvar-challenge.shop.acme.com'] = 1;
        later('+2 minutes');
        $world->act('OrganizationDomains.check', ['domain' => $globex->id], 'm-9', 'globex.example.test');

        expect($world->domains->tenantForHost('shop.acme.com'))->toBe('acme')
            ->and(at($world, 'globex', $globex->id)->status)->toBe(TenantDomainStatus::Pending);
    });
});

describe('the domains page', function () {
    it('shows the last check, the next, the reason and when "check now" may be asked again', function () {
        $world = new AdminWorld();
        verified($world);

        $domain = $world->page('/settings/domains')['domains'][0];

        expect($domain['status'])->toBe('verified')
            ->and($domain['checkedAt'])->toBe(AdminWorld::$now->toJSON())
            ->and($domain['nextCheckAt'])->not->toBeNull()
            ->and($domain['checkableAt'])->toBe(AdminWorld::$now->addMinute()->toJSON())
            ->and($domain['reason'])->not->toBe('')
            ->and($domain['lapse'])->toBeNull()
            ->and($domain['lapsesAt'])->toBeNull();
    });

    it('checks the ownership record at "check now", throttled to once a minute', function () {
        $world = new AdminWorld();
        $id = verified($world);
        $world->dns->texts = [];

        later('+30 seconds');
        $world->act('OrganizationDomains.check', ['domain' => $id]);
        $throttled = at($world, 'acme', $id)->status;

        later('+30 seconds');
        $world->act('OrganizationDomains.check', ['domain' => $id]);

        expect($throttled)->toBe(TenantDomainStatus::Verified)
            ->and(at($world, 'acme', $id)->status)->toBe(TenantDomainStatus::Failing)
            ->and($world->activity->changes())->toContain('tenant.domain_failing');
    });
});

describe('the record of each change', function () {
    it('files a takeover\'s lapse in the holder\'s log as the system\'s, with nothing of the claimant\'s request', function () {
        $world = new AdminWorld(audited: true);
        verified($world);
        $world->dns->texts = [];
        later('+1 day');
        run($world);
        $world->act('OrganizationDomains.add', ['host' => 'shop.acme.com'], 'm-9', 'globex.example.test');
        $globex = $world->domains->forTenant('globex')[0];
        $world->dns->texts['_tinvar-challenge.shop.acme.com'] = ['tinvar-domain=' . $globex->token];
        later('+2 minutes');

        // As the Kernel does around every request it handles.
        $audit = $world->audit ?? throw new \LogicException('No audit log.');
        $depth = $audit->enterRequest(new \Tinvar\Http\Request('POST', '/_action/x', ['host' => 'globex.example.test'], remoteAddress: '203.0.113.9'), 'req-globex');
        $world->act('OrganizationDomains.check', ['domain' => $globex->id], 'm-9', 'globex.example.test');
        $audit->leaveRequest($depth);

        $lapsed = array_values(array_filter(
            $world->orm->actAs('acme', static fn (): array => $audit->trail()->recent()),
            static fn ($e): bool => $e->event === TenantChange::DomainLapsed->value,
        ));
        expect($world->domains->tenantForHost('shop.acme.com'))->toBe('globex')
            ->and($lapsed)->toHaveCount(1)
            ->and($lapsed[0]->actor->kind)->toBe(\Tinvar\Audit\ActorKind::System)
            ->and($lapsed[0]->actor->id)->toBeNull()
            ->and($lapsed[0]->request)->toBeNull()
            ->and($lapsed[0]->action)->toBeNull()
            ->and($lapsed[0]->panel)->toBeNull();
    });

    it('goes in the audit log of the domain\'s tenant alone, made by nobody', function () {
        $world = new AdminWorld(audited: true);
        verified($world);
        $world->dns->texts = [];
        later('+1 day');

        run($world);

        $acme = $world->orm->actAs('acme', static fn (): array => $world->audit?->trail()->recent() ?? []);
        $globex = $world->orm->actAs('globex', static fn (): array => $world->audit?->trail()->recent() ?? []);

        expect(array_map(static fn ($e): string => $e->event, $acme))->toContain(TenantChange::DomainFailing->value)
            ->and(array_map(static fn ($e): string => $e->event, $globex))->not->toContain(TenantChange::DomainFailing->value);
    });
});

/** @param \Closure(AdminWorld): void $test */
function onServer(string $driver, \Closure $test): void
{
    Databases::inScratchSchema($driver, static function (\PDO $pdo) use ($test, $driver): void {
        $pdo->exec('CREATE TABLE notes (id VARCHAR(64) PRIMARY KEY, body TEXT NOT NULL, tenant_id VARCHAR(191) NOT NULL)');
        $test(new AdminWorld(new PdoConnection($pdo, "reverification-{$driver}")));
    });
}

it('runs the whole lapse and a handover on Postgres', function () {
    onServer('pgsql', static function (AdminWorld $world): void {
        $id = verified($world);
        $world->dns->texts = [];
        later('+1 day');
        run($world);
        later('+8 days');
        run($world);
        $world->act('OrganizationDomains.add', ['host' => 'shop.acme.com'], 'm-9', 'globex.example.test');
        $globex = $world->domains->forTenant('globex')[0];
        $world->dns->texts['_tinvar-challenge.shop.acme.com'] = ['tinvar-domain=' . $globex->token];
        later('+2 minutes');
        $world->act('OrganizationDomains.check', ['domain' => $globex->id], 'm-9', 'globex.example.test');

        expect(at($world, 'acme', $id)->status)->toBe(TenantDomainStatus::Deactivated)
            ->and($world->domains->tenantForHost('shop.acme.com'))->toBe('globex');
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('runs the whole lapse and a handover on MySQL', function () {
    onServer('mysql', static function (AdminWorld $world): void {
        $acme = verified($world);
        $world->dns->texts = [];
        later('+1 day');
        run($world);
        $world->act('OrganizationDomains.add', ['host' => 'shop.acme.com'], 'm-9', 'globex.example.test');
        $globex = $world->domains->forTenant('globex')[0];
        $world->dns->texts['_tinvar-challenge.shop.acme.com'] = ['tinvar-domain=' . $globex->token];
        later('+2 minutes');
        $world->act('OrganizationDomains.check', ['domain' => $globex->id], 'm-9', 'globex.example.test');
        later('+31 days');
        run($world);

        expect(at($world, 'acme', $acme)->status)->toBe(TenantDomainStatus::Pending)
            ->and($world->domains->tenantForHost('shop.acme.com'))->toBe('globex');
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

/** A domains table as define() made it before ADR 0198, upgraded, then written and read as today's. */
function upgraded(PdoConnection $connection): void
{
    $schema = new \Tinvar\Orm\Schema\Schema($connection, \Tinvar\Orm\Schema\Dialect::for($connection));
    $schema->create('old_domains', static function (\Tinvar\Orm\Schema\Table $domains): void {
        $domains->uuid('id')->primary();
        $domains->string('tenant_id', 191);
        $domains->string('host', 253);
        $domains->string('held_host', 253)->nullable()->unique();
        $domains->enum('status', TenantDomainStatus::class);
        $domains->string('token', 64);
        $domains->text('reason');
        $domains->timestamp('created_at');
        $domains->timestamp('checked_at')->nullable();
        $domains->timestamp('verified_at')->nullable();
        $domains->timestamp('failing_since')->nullable();
        $domains->timestamp('deactivated_at')->nullable();
    });

    \Tinvar\Tenancy\SelfService\TenantDomains::defineReverification($schema, 'old_domains');

    $orm = new \Tinvar\Orm\Orm($connection, new \Tinvar\Orm\IdentityMap('upgraded'), \Tinvar\Tenancy\Tenancy::tree());
    $domains = new \Tinvar\Tenancy\SelfService\TenantDomains($orm, 'old_domains', static fn (): CarbonImmutable => CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $domain = $domains->add('acme', 'shop.acme.com');
    $domains->activate('acme', $domain->id, $domains->now(), 'Proved.');
    $in = $domains->ofTenant('acme', $domain->id) ?? throw new \LogicException('Not added.');
    $domains->lapse($in, $domains->now(), $domains->now()->addDay(), DomainLapse::Ownership, 'Gone.');

    $after = $domains->ofTenant('acme', $domain->id);
    expect($after?->status)->toBe(TenantDomainStatus::Deactivated)
        ->and($after?->lapse)->toBe(DomainLapse::Ownership)
        ->and($after?->nextCheckAt?->equalTo($domains->now()->addDay()))->toBeTrue()
        ->and($domains->dueForCheck($domains->now()->addDays(2), 10))->toHaveCount(1);
}

it('upgrades a table from before the re-verification on SQLite', function () {
    upgraded(new PdoConnection(new \PDO('sqlite::memory:'), 'upgrade-sqlite'));
});

it('upgrades a table from before the re-verification on Postgres and MySQL', function (string $driver) {
    $missing = $driver === 'pgsql' ? Databases::postgresMissing() : Databases::mysqlMissing();
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }
    Databases::inScratchSchema($driver, static fn (\PDO $pdo) => upgraded(new PdoConnection($pdo, "upgrade-{$driver}")));
})->with(['pgsql', 'mysql']);
