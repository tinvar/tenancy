<?php

declare(strict_types=1);

/**
 * The ready-made custom domains page (ADR 0159), through the real Kernel:
 * acme's owner adds a domain, sees the records to create, and watches it
 * go from pending to verified once its TXT record and CNAME are there; the
 * daily check and Caddy's ask take it from there. And every way one tenant
 * could reach another's domains, or claim one it does not own -- a host
 * another tenant holds, a platform host, a dangling domain still pointing
 * here -- is refused.
 */

namespace Tinvar\Tests\Tenancy\SelfService\Domains;

use Carbon\CarbonImmutable;
use Tinvar\Http\Request;
use Tinvar\Identity\TlsAskEndpoint;
use Tinvar\Tests\Tenancy\Fixtures\Domains\App;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdminWorld;

require_once __DIR__ . '/Fixtures/self_service.php';

/** Adds $host as acme's owner, and returns the domain's id. */
function add(AdminWorld $world, string $host = 'shop.acme.com', string $tenant = 'acme', string $as = 'm-1'): string
{
    $response = $world->act('OrganizationDomains.add', ['host' => $host], $as, "{$tenant}.example.test");
    expect($response->status)->toBe(200, $response->body());

    return App::json($response)['value']['domain'] ?? App::json($response)['data']['domain'] ?? (string) ($world->domains->forTenant($tenant)[0]->id ?? '');
}

/** @return list<string> the field errors under `host` */
function refusal(\Tinvar\Http\Response $response): array
{
    expect($response->status)->toBe(422, $response->body());

    return App::json($response)['errors']['host'] ?? App::json($response)['error']['errors']['host'] ?? [];
}

describe('adding a domain', function () {
    it('claims it as pending, and shows the TXT and CNAME records to create', function () {
        $world = new AdminWorld();
        add($world);

        $page = $world->page('/settings/domains');
        $domain = $world->domains->forTenant('acme')[0];

        expect($page['limit'])->toBe(3)
            ->and($page['canManage'])->toBeTrue()
            ->and($page['domains'])->toHaveCount(1)
            ->and($page['domains'][0]['host'])->toBe('shop.acme.com')
            ->and($page['domains'][0]['status'])->toBe('pending')
            ->and($page['domains'][0]['records'])->toBe([
                ['type' => 'TXT', 'name' => '_tinvar-challenge.shop.acme.com', 'value' => 'tinvar-domain=' . $domain->token, 'purpose' => 'ownership'],
                ['type' => 'CNAME', 'name' => 'shop.acme.com', 'value' => 'domains.example.test', 'purpose' => 'pointing'],
            ])
            ->and($world->activity->changes())->toBe(['tenant.domain_added'])
            // Pending serves nothing: no tenant holds the host yet.
            ->and($world->domains->tenantForHost('shop.acme.com'))->toBeNull();
    });

    it('takes the host however it is typed, and refuses what is no host name', function (string $typed, ?string $kept) {
        $world = new AdminWorld();
        $response = $world->act('OrganizationDomains.add', ['host' => $typed]);

        if ($kept === null) {
            expect(refusal($response)[0])->toContain('Enter');
        } else {
            expect($response->status)->toBe(200, $response->body())
                ->and($world->domains->forTenant('acme')[0]->host)->toBe($kept);
        }
    })->with([
        'mixed case and a trailing dot' => ['Shop.Acme.COM.', 'shop.acme.com'],
        'a URL' => ['https://shop.acme.com/', null],
        'a port' => ['shop.acme.com:8443', null],
        'an address' => ['203.0.113.7', null],
        'a single label' => ['acme', null],
        'localhost' => ['shop.localhost', null],
    ]);

    it('refuses a platform host: the apex, a subdomain of it, the staff\'s host', function (string $host) {
        expect(refusal(new AdminWorld()->act('OrganizationDomains.add', ['host' => $host]))[0])->toContain('platform\'s own');
    })->with(['example.test', 'globex.example.test', 'admin.example.test', 'anything.example.test']);

    it('refuses a host another tenant holds, without naming whose', function () {
        $world = new AdminWorld();

        // initech.io is initech's custom domain, by the application's realms.
        $message = refusal($world->act('OrganizationDomains.add', ['host' => 'initech.io']))[0];

        expect($message)->toContain('already in use')->not->toContain('nitech\'s');
    });

    it('refuses a host one tenant proved, to every other', function () {
        $world = new AdminWorld();
        $id = add($world, 'brand.example.org', 'globex', 'm-9');
        $world->dns->prove('brand.example.org', $world->domains->ofTenant('globex', $id)?->token);
        $world->act('OrganizationDomains.check', ['domain' => $id], 'm-9', 'globex.example.test');

        expect($world->domains->tenantForHost('brand.example.org'))->toBe('globex')
            ->and(refusal($world->act('OrganizationDomains.add', ['host' => 'brand.example.org']))[0])->toContain('already in use');
    });

    it('bounds how many domains a tenant has, pending ones counted', function () {
        $world = new AdminWorld(limit: 2);
        add($world, 'one.acme.com');
        add($world, 'two.acme.com');

        expect(refusal($world->act('OrganizationDomains.add', ['host' => 'three.acme.com']))[0])->toContain('the most it may have')
            ->and($world->domains->countForTenant('acme'))->toBe(2);
    });

    it('refuses one the tenant has already', function () {
        $world = new AdminWorld();
        add($world);

        expect(refusal($world->act('OrganizationDomains.add', ['host' => 'shop.acme.com']))[0])->toContain('already one of your domains');
    });

    it('lets a member see the domains, and change nothing', function () {
        $world = new AdminWorld();
        add($world);

        expect($world->page('/settings/domains', 'm-3')['canManage'])->toBeFalse()
            ->and($world->act('OrganizationDomains.add', ['host' => 'other.acme.com'], 'm-3')->status)->toBe(403)
            ->and($world->domains->countForTenant('acme'))->toBe(1);
    });

    it('refuses someone who is no member of the tenant, and anyone not signed in', function () {
        $world = new AdminWorld();

        // Dee owns initech, not acme.
        expect($world->status('/settings/domains', 'm-4'))->toBe(403)
            ->and($world->act('OrganizationDomains.add', ['host' => 'x.example.org'], 'm-4')->status)->toBe(403)
            ->and($world->status('/settings/domains', 'nobody'))->toBeIn([401, 403]);
    });
});

describe('verifying a domain', function () {
    it('keeps it pending while either record is missing, saying which', function () {
        $world = new AdminWorld();
        $id = add($world);
        $token = $world->domains->ofTenant('acme', $id)?->token;

        $world->dns->aliases['shop.acme.com'] = 'domains.example.test';
        $world->act('OrganizationDomains.check', ['domain' => $id]);
        $first = $world->domains->ofTenant('acme', $id);

        AdminWorld::$now = AdminWorld::$now->addMinute();
        unset($world->dns->aliases['shop.acme.com']);
        $world->dns->texts['_tinvar-challenge.shop.acme.com'] = ['tinvar-domain=' . $token];
        $world->dns->addresses['shop.acme.com'] = ['198.51.100.1'];
        $world->act('OrganizationDomains.check', ['domain' => $id]);
        $second = $world->domains->ofTenant('acme', $id);

        expect($first?->status->value)->toBe('pending')
            ->and($first?->reason)->toContain('TXT record')
            ->and($second?->status->value)->toBe('pending')
            ->and($second?->reason)->toContain('does not point at the platform');
    });

    it('puts it in service once it is proved, and the host is the tenant\'s from then on', function () {
        $world = new AdminWorld();
        $id = add($world);
        $world->dns->prove('shop.acme.com', $world->domains->ofTenant('acme', $id)?->token);

        $response = $world->act('OrganizationDomains.check', ['domain' => $id]);

        expect($response->status)->toBe(200, $response->body())
            ->and($world->page('/settings/domains')['domains'][0]['status'])->toBe('verified')
            ->and($world->domains->tenantForHost('shop.acme.com'))->toBe('acme')
            ->and($world->domains->primaryFor('acme'))->toBe('shop.acme.com')
            ->and($world->kit->realms->forHost('shop.acme.com')?->tenant)->toBe('acme')
            ->and($world->activity->changes())->toBe(['tenant.domain_added', 'tenant.domain_verified']);
    });

    it('never serves a dangling domain to a tenant that cannot publish its record', function () {
        // A domain globex gave up still points at the platform.
        $world = new AdminWorld();
        $world->dns->prove('old.example.org', null);
        $world->dns->texts['_tinvar-challenge.old.example.org'] = ['tinvar-domain=' . str_repeat('ab', 16)];
        $id = add($world, 'old.example.org');

        $world->act('OrganizationDomains.check', ['domain' => $id]);

        expect($world->domains->ofTenant('acme', $id)?->status->value)->toBe('pending')
            ->and($world->domains->tenantForHost('old.example.org'))->toBeNull();
    });

    it('answers a check asked again at once from the last one, with no lookup', function () {
        $world = new AdminWorld();
        $id = add($world);
        $world->act('OrganizationDomains.check', ['domain' => $id]);
        $asked = \count($world->dns->asked);

        $world->act('OrganizationDomains.check', ['domain' => $id]);

        expect(\count($world->dns->asked))->toBe($asked);
    });

    it('marks one in service as failing while its DNS cannot be read, then deactivates it after the grace', function () {
        $world = new AdminWorld();
        $id = add($world);
        $world->dns->prove('shop.acme.com', $world->domains->ofTenant('acme', $id)?->token);
        $world->act('OrganizationDomains.check', ['domain' => $id]);

        $world->dns->failing['shop.acme.com'] = true;
        AdminWorld::$now = AdminWorld::$now->addMinute();
        $world->act('OrganizationDomains.check', ['domain' => $id]);
        $failing = $world->page('/settings/domains')['domains'][0];

        // Past the hour's grace: the next check deactivates it.
        AdminWorld::$now = AdminWorld::$now->addHours(2);
        $world->act('OrganizationDomains.check', ['domain' => $id]);

        expect($failing['status'])->toBe('failing')
            ->and($world->domains->tenantForHost('shop.acme.com'))->toBeNull()
            ->and($world->page('/settings/domains')['domains'][0]['status'])->toBe('deactivated');
    });

    it('deactivates one whose DNS moved elsewhere at once, and puts it back only once proved again', function () {
        $world = new AdminWorld();
        $id = add($world);
        $token = $world->domains->ofTenant('acme', $id)?->token;
        $world->dns->prove('shop.acme.com', $token);
        $world->act('OrganizationDomains.check', ['domain' => $id]);

        $world->dns->aliases['shop.acme.com'] = 'someone-else.example.net';
        $world->dns->addresses['someone-else.example.net'] = ['198.51.100.9'];
        AdminWorld::$now = AdminWorld::$now->addMinute();
        $world->act('OrganizationDomains.check', ['domain' => $id]);
        $deactivated = $world->domains->ofTenant('acme', $id)?->status->value;

        $world->dns->prove('shop.acme.com', $token);
        AdminWorld::$now = AdminWorld::$now->addMinute();
        $world->act('OrganizationDomains.check', ['domain' => $id]);

        expect($deactivated)->toBe('deactivated')
            ->and($world->domains->ofTenant('acme', $id)?->status->value)->toBe('verified');
    });

    it('is re-verified by the daily task through the same records', function () {
        $world = new AdminWorld();
        $id = add($world);
        $world->dns->prove('shop.acme.com', $world->domains->ofTenant('acme', $id)?->token);
        $world->act('OrganizationDomains.check', ['domain' => $id]);

        $world->dns->aliases['shop.acme.com'] = 'elsewhere.example.net';
        $world->dns->addresses['elsewhere.example.net'] = ['198.51.100.9'];
        AdminWorld::$now = AdminWorld::$now->addDay();
        $outcomes = new \Tinvar\Identity\CustomDomainVerification($world->domains, new \Tinvar\Identity\DomainVerifier(['domains.example.test'], [], $world->dns), 3600, static fn (): CarbonImmutable => AdminWorld::$now)->verifyDue()['outcomes'];

        expect($outcomes)->toBe(['shop.acme.com' => \Tinvar\Identity\DomainStatus::Deactivated])
            ->and($world->domains->ofTenant('acme', $id)?->status->value)->toBe('deactivated');
    });
});

describe('another tenant\'s domains', function () {
    it('are never listed, checked or removed from this tenant\'s pages', function () {
        $world = new AdminWorld();
        $globex = add($world, 'brand.example.org', 'globex', 'm-9');

        // Ann is a member of globex too, but on acme's host she is acme's.
        expect($world->page('/settings/domains')['domains'])->toBe([])
            ->and($world->act('OrganizationDomains.check', ['domain' => $globex])->status)->toBe(404)
            ->and($world->act('OrganizationDomains.remove', ['domain' => $globex])->status)->toBe(404)
            ->and($world->domains->countForTenant('globex'))->toBe(1);
    });

    it('are never in this tenant\'s status query', function () {
        $world = new AdminWorld();
        add($world, 'brand.example.org', 'globex', 'm-9');
        add($world);

        $response = $world->app->post('/_query/tenancy.domains', 'acme.example.test', [], ['x-user' => 'm-1']);

        expect($response->status)->toBe(200, $response->body())
            ->and($response->body())->toContain('shop.acme.com')->not->toContain('brand.example.org');
    });
});

describe('removing a domain', function () {
    it('takes it out of service at once', function () {
        $world = new AdminWorld();
        $id = add($world);
        $world->dns->prove('shop.acme.com', $world->domains->ofTenant('acme', $id)?->token);
        $world->act('OrganizationDomains.check', ['domain' => $id]);

        $response = $world->act('OrganizationDomains.remove', ['domain' => $id]);

        expect($response->status)->toBe(200, $response->body())
            ->and($world->domains->tenantForHost('shop.acme.com'))->toBeNull()
            ->and($world->activity->changes())->toContain('tenant.domain_removed');
    });
});

describe('Caddy\'s TLS ask', function () {
    it('is mounted by the Kernel from the container, and allows a certificate for a verified domain alone', function () {
        $world = new AdminWorld();
        $id = add($world);
        $ask = static fn (string $domain): int => $world->app->kernel->handle(
            new Request('GET', TlsAskEndpoint::PATH, ['host' => 'localhost'], ['domain' => $domain], remoteAddress: '127.0.0.1'),
            static fn (): \Tinvar\Http\Response => \Tinvar\Http\Response::make(500, 'not a page'),
        )->status;

        $pending = $ask('shop.acme.com');
        $world->dns->prove('shop.acme.com', $world->domains->ofTenant('acme', $id)?->token);
        $world->act('OrganizationDomains.check', ['domain' => $id]);

        expect($pending)->toBe(404)
            ->and($ask('shop.acme.com'))->toBe(200)
            ->and($ask('unknown.example.org'))->toBe(404);
    });
});
