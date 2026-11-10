<?php

declare(strict_types=1);

/**
 * The invites sent to whoever is signed in, accepted or declined without
 * the emailed link (ADR 0201): Dee, invited to acme by Ann and to globex by
 * Eve, sees both, from wherever she is signed in; accepting one joins her
 * as its emailed link would, declining closes it, and nobody else -- Eve,
 * an API token, an impersonator -- takes or turns down an invite of hers.
 */

namespace Tinvar\Tests\Tenancy\SelfService\Personal;

use Tinvar\Accounts\AccountUser;
use Tinvar\Accounts\VerifiedEmails;
use Tinvar\Http\HttpException;
use Tinvar\Tenancy\SelfService\PersonalInvitations;
use Tinvar\Tenancy\SelfService\TenantInviteDeclined;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdminWorld;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\Person;

require_once __DIR__ . '/Fixtures/self_service.php';

/** An account keeping a second address it proved, beside the one it signs in with. */
final readonly class TwoAddresses implements AccountUser, VerifiedEmails
{
    /** @param list<string> $verified */
    public function __construct(private string $id, private string $email, private array $verified) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }

    public function accountEmail(): string
    {
        return $this->email;
    }

    public function accountName(): string
    {
        return 'Fay';
    }

    public function accountPasswordHash(): string
    {
        return '';
    }

    public function verifiedEmails(): array
    {
        return $this->verified;
    }
}

/**
 * $work signed in as $user, as the session the fixture's middleware makes would sign them in.
 *
 * @template T
 * @param \Closure(PersonalInvitations): T $work
 * @return T
 */
function signedIn(AdminWorld $world, string $user, \Closure $work, ?string $apiToken = null, ?string $impersonator = null): mixed
{
    $world->auth->login(new Person($user, '', ''), '1', $apiToken, $impersonator);

    try {
        return $work($world->kit->personalInvitations);
    } finally {
        $world->auth->reset();
    }
}

/** Dee invited to acme as an admin by Ann, then to globex as a member by Eve: the acme invite's id, then globex's. */
function invited(AdminWorld $world): array
{
    $world->act('OrganizationSettings.invite', ['email' => 'dee@example.net', 'role' => 'admin']);
    $acme = $world->page('/settings')['invites'][0]['id'];
    $world->act('OrganizationSettings.invite', ['email' => 'Dee@Example.net', 'role' => 'member'], 'm-9', 'globex.example.test');
    $globex = $world->page('/settings', 'm-9', 'globex.example.test')['invites'][0]['id'];

    return [$acme, $globex];
}

function refusal(\Closure $work): int
{
    try {
        $work();
    } catch (HttpException $e) {
        return $e->status;
    }

    return 0;
}

describe('the invites to the signed-in person', function () {
    it('lists every tenant\'s open invites to their address, newest first, by organization and role', function () {
        $world = new AdminWorld();
        [$acme, $globex] = invited($world);

        $pending = signedIn($world, 'm-4', static fn (PersonalInvitations $invites): array => $invites->pending());

        expect(array_map(static fn ($p): array => [$p->id, $p->tenant, $p->organization, $p->panel, $p->email, $p->role, $p->roleValue], $pending))->toBe([
            [$globex, 'globex', 'Globex', 'customer', 'dee@example.net', 'Member', 'member'],
            [$acme, 'acme', 'Acme', 'customer', 'dee@example.net', 'Admin', 'admin'],
        ])
            ->and($pending[0]->expiresAt->getTimestamp())->toBeGreaterThan(time() + 6 * 86_400)
            ->and(signedIn($world, 'm-4', static fn (PersonalInvitations $invites): array => $invites->pending('customer')))->toHaveCount(2)
            ->and(signedIn($world, 'm-4', static fn (PersonalInvitations $invites): array => $invites->pending('admin')))->toBe([])
            // Nobody else's: Cy has none, and Eve, who sent one, is sent none.
            ->and(signedIn($world, 'm-3', static fn (PersonalInvitations $invites): array => $invites->pending()))->toBe([])
            ->and(signedIn($world, 'm-9', static fn (PersonalInvitations $invites): array => $invites->pending()))->toBe([])
            ->and(refusal(static fn () => $world->kit->personalInvitations->pending()))->toBe(401)
            // An API token is one tenant's, and reads no other tenant's invites.
            ->and(refusal(static fn () => signedIn($world, 'm-4', static fn (PersonalInvitations $invites): array => $invites->pending(), apiToken: 'tok-1')))->toBe(403);
    });

    it('lists no invite once its inviter may no longer send it', function () {
        $world = new AdminWorld();
        $world->act('OrganizationSettings.invite', ['email' => 'dee@example.net', 'role' => 'admin'], 'm-2');
        $id = $world->page('/settings')['invites'][0]['id'];
        $world->members->changeRole('acme', 'customer', 'm-2', 'member');

        expect(signedIn($world, 'm-4', static fn (PersonalInvitations $invites): array => $invites->pending()))->toBe([])
            ->and(refusal(static fn () => signedIn($world, 'm-4', static fn (PersonalInvitations $invites) => $invites->accept('acme', $id))))->toBe(404)
            ->and($world->members->roleOf('acme', 'customer', 'm-4'))->toBeNull();
    });

    it('reads every address an account lists as verified, and no other', function () {
        $world = new AdminWorld();
        $world->people->people['m-5'] = new TwoAddresses('m-5', 'fay@example.net', ['fay@example.net', 'Fay@Work.example']);
        $world->people->people['m-6'] = new TwoAddresses('m-6', 'dee@example.net', []);
        $world->act('OrganizationSettings.invite', ['email' => 'fay@work.example', 'role' => 'member']);
        invited($world);

        $fay = signedIn($world, 'm-5', static fn (PersonalInvitations $invites): array => $invites->pending());

        expect(array_map(static fn ($p): array => [$p->tenant, $p->email], $fay))->toBe([['acme', 'fay@work.example']])
            // Its sign-in address is not one it lists as verified.
            ->and(signedIn($world, 'm-6', static fn (PersonalInvitations $invites): array => $invites->pending()))->toBe([]);

        signedIn($world, 'm-5', static fn (PersonalInvitations $invites) => $invites->accept('acme', $fay[0]->id));
        expect($world->members->roleOf('acme', 'customer', 'm-5'))->toBe('member');
    });
});

describe('accepting one', function () {
    it('joins as its emailed link does: the same membership, the same record', function () {
        $byToken = new AdminWorld();
        $byToken->act('OrganizationSettings.invite', ['email' => 'dee@example.net', 'role' => 'admin']);
        $byToken->act('OrganizationInvitation.accept', ['token' => $byToken->invites->lastToken()], 'm-4');

        $byId = new AdminWorld();
        [$acme] = invited($byId);
        $accepted = signedIn($byId, 'm-4', static fn (PersonalInvitations $invites) => $invites->accept('acme', $acme));

        $acmeRecords = static fn (AdminWorld $world): array => array_values(array_filter($world->activity->records, static fn (array $r): bool => $r['tenant'] === 'acme'));
        expect($accepted->tenant)->toBe('acme')
            ->and($accepted->role)->toBe('admin')
            ->and($byId->members->roleOf('acme', 'customer', 'm-4'))->toBe('admin')
            ->and($byId->members->roleOf('acme', 'customer', 'm-4'))->toBe($byToken->members->roleOf('acme', 'customer', 'm-4'))
            ->and($acmeRecords($byId))->toBe($acmeRecords($byToken))
            ->and($acmeRecords($byId)[1])->toBe(['change' => 'tenant.invite_accepted', 'tenant' => 'acme', 'actor' => 'm-4', 'details' => ['email' => 'dee@example.net', 'role' => 'admin']])
            // Globex's is still open, and acme's is listed no more.
            ->and(array_map(static fn ($p): string => $p->tenant, signedIn($byId, 'm-4', static fn (PersonalInvitations $invites): array => $invites->pending())))->toBe(['globex'])
            ->and($byId->members->roleOf('globex', 'customer', 'm-4'))->toBeNull();
    });

    it('is once, and uses its emailed link: neither opens again', function () {
        $world = new AdminWorld();
        $world->act('OrganizationSettings.invite', ['email' => 'dee@example.net', 'role' => 'admin']);
        $token = $world->invites->lastToken();
        $id = $world->page('/settings')['invites'][0]['id'];

        signedIn($world, 'm-4', static fn (PersonalInvitations $invites) => $invites->accept('acme', $id));

        expect(refusal(static fn () => signedIn($world, 'm-4', static fn (PersonalInvitations $invites) => $invites->accept('acme', $id))))->toBe(404)
            ->and(refusal(static fn () => signedIn($world, 'm-4', static fn (PersonalInvitations $invites) => $invites->decline('acme', $id))))->toBe(404)
            ->and($world->page('/invite', 'm-4', query: ['token' => $token])['open'])->toBeFalse()
            ->and($world->page('/settings')['invites'])->toBe([])
            ->and($world->activity->changes())->toBe(['tenant.member_invited', 'tenant.invite_accepted']);
    });

    it('is refused, one 404 alike, for an invite not sent to the signed-in account, or not in the tenant named', function () {
        $world = new AdminWorld();
        [$acme, $globex] = invited($world);

        foreach ([['m-9', 'acme', $acme], ['m-3', 'acme', $acme], ['m-4', 'globex', $acme], ['m-4', 'acme', $globex], ['m-4', 'acme', 'no-such-invite'], ['m-4', "acme\n", $acme], ['m-4', '', $acme]] as [$user, $tenant, $id]) {
            expect(refusal(static fn () => signedIn($world, $user, static fn (PersonalInvitations $invites) => $invites->accept($tenant, $id))))->toBe(404)
                ->and(refusal(static fn () => signedIn($world, $user, static fn (PersonalInvitations $invites) => $invites->decline($tenant, $id))))->toBe(404);
        }

        expect($world->members->roleOf('acme', 'customer', 'm-9'))->toBeNull()
            ->and($world->members->roleOf('acme', 'customer', 'm-4'))->toBeNull()
            ->and($world->activity->changes())->toBe(['tenant.member_invited', 'tenant.member_invited'])
            ->and(signedIn($world, 'm-4', static fn (PersonalInvitations $invites): array => $invites->pending()))->toHaveCount(2);
    });

    it('takes the person signed in as themselves: nobody, an API token or an impersonator takes or turns one down', function () {
        $world = new AdminWorld();
        [$acme] = invited($world);

        expect(refusal(static fn () => $world->kit->personalInvitations->accept('acme', $acme)))->toBe(401)
            ->and(refusal(static fn () => $world->kit->personalInvitations->decline('acme', $acme)))->toBe(401)
            ->and(refusal(static fn () => signedIn($world, 'm-4', static fn (PersonalInvitations $invites) => $invites->accept('acme', $acme), apiToken: 'tok-1')))->toBe(403)
            ->and(refusal(static fn () => signedIn($world, 'm-4', static fn (PersonalInvitations $invites) => $invites->decline('acme', $acme), apiToken: 'tok-1')))->toBe(403)
            ->and(refusal(static fn () => signedIn($world, 'm-4', static fn (PersonalInvitations $invites) => $invites->accept('acme', $acme), impersonator: 'm-1')))->toBe(403)
            ->and(refusal(static fn () => signedIn($world, 'm-4', static fn (PersonalInvitations $invites) => $invites->decline('acme', $acme), impersonator: 'm-1')))->toBe(403)
            // Someone signed in whom the application has no account for.
            ->and(refusal(static fn () => signedIn($world, 'ghost', static fn (PersonalInvitations $invites) => $invites->accept('acme', $acme))))->toBe(401)
            ->and($world->members->roleOf('acme', 'customer', 'm-4'))->toBeNull()
            ->and(signedIn($world, 'm-4', static fn (PersonalInvitations $invites): array => $invites->pending()))->toHaveCount(2);
    });

    it('is recorded in the audit log of the invite\'s tenant, not the one the request is in', function () {
        $world = new AdminWorld(audited: true);
        [$acme, $globex] = invited($world);

        $world->orm->actAs('initech', static fn () => signedIn($world, 'm-4', static function (PersonalInvitations $invites) use ($acme, $globex): void {
            $invites->accept('acme', $acme);
            $invites->decline('globex', $globex);
        }));

        $events = static fn (string $tenant): array => array_map(static fn ($e): string => $e->event, $world->orm->actAs($tenant, static fn (): array => $world->audit?->trail()->recent() ?? []));
        expect($events('acme'))->toContain('tenant.invite_accepted')
            ->and($events('acme'))->not->toContain('tenant.invite_declined')
            ->and($events('globex'))->toContain('tenant.invite_declined')
            ->and($events('globex'))->not->toContain('tenant.invite_accepted')
            ->and($events('initech'))->toBe([]);
    });
});

describe('declining one', function () {
    it('closes it, marked declined, records and announces it, and joins nobody', function () {
        $world = new AdminWorld();
        $world->act('OrganizationSettings.invite', ['email' => 'dee@example.net', 'role' => 'admin']);
        $token = $world->invites->lastToken();
        $id = $world->page('/settings')['invites'][0]['id'];

        $declined = signedIn($world, 'm-4', static fn (PersonalInvitations $invites) => $invites->decline('acme', $id));
        $announced = $world->events->dispatched(TenantInviteDeclined::class);

        expect($declined->declinedAt)->not->toBeNull()
            ->and($declined->role)->toBe('admin')
            ->and($world->kit->invitations->addressedTo(['dee@example.net']))->toBe([])
            ->and($world->members->roleOf('acme', 'customer', 'm-4'))->toBeNull()
            ->and($world->activity->records[1])->toBe(['change' => 'tenant.invite_declined', 'tenant' => 'acme', 'actor' => 'm-4', 'details' => ['email' => 'dee@example.net', 'role' => 'admin']])
            ->and($announced)->toHaveCount(1)
            ->and([$announced[0]->tenant, $announced[0]->invite, $announced[0]->panel, $announced[0]->email, $announced[0]->role, $announced[0]->user, $announced[0]->inviter])
            ->toBe(['acme', $id, 'customer', 'dee@example.net', 'admin', 'm-4', 'm-1'])
            ->and($announced[0]->at->getTimestamp())->toBe($declined->declinedAt)
            // Its emailed link opens nothing, and it is no open invite of acme's.
            ->and($world->page('/invite', 'm-4', query: ['token' => $token])['open'])->toBeFalse()
            ->and($world->act('OrganizationInvitation.accept', ['token' => $token], 'm-4')->status)->toBe(422)
            ->and($world->page('/settings')['invites'])->toBe([])
            ->and(refusal(static fn () => signedIn($world, 'm-4', static fn (PersonalInvitations $invites) => $invites->accept('acme', $id))))->toBe(404)
            ->and(signedIn($world, 'm-4', static fn (PersonalInvitations $invites): array => $invites->pending()))->toBe([]);
    });
});
