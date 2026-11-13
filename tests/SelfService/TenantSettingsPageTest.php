<?php

declare(strict_types=1);

/**
 * The ready-made settings and invitation pages (ADR 0161), through the real
 * Kernel: acme's owner Ann renames it, moves its slug, invites, re-roles
 * and removes members; Bob, an admin, may manage members but never climb
 * above his role; Cy, a member, may change nothing. Nothing on acme's host
 * reaches globex's members or invites, and an invite is accepted by the
 * account of its address, in its tenant alone.
 */

namespace Tinvar\Tests\Tenancy\SelfService\Settings;

use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\HttpException;
use Tinvar\Http\Response;
use Tinvar\Tenancy\SelfService\TenantSettings;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\Person;
use Tinvar\Validation\ValidationException;
use Tinvar\Mail\Address;
use Tinvar\Mail\ArrayTransport;
use Tinvar\Mail\LinkPolicy;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\MailRenderer;
use Tinvar\Mail\RenderedMail;
use Tinvar\Tenancy\SelfService\SendTenantInvite;
use Tinvar\Tests\Tenancy\Fixtures\Domains\App;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdminWorld;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\InviteMails;

require_once __DIR__ . '/Fixtures/self_service.php';

/** The world's settings, with invite budgets of $perHour and $open. */
function budgeted(AdminWorld $world, ArrayThrottleStore $throttles, int $perHour = 100, int $open = 100, int $perActor = 100): TenantSettings
{
    return new TenantSettings(
        $world->tenants,
        $world->members,
        $world->kit->invitations,
        $world->kit->invitePolicy,
        $world->invites,
        throttles: $throttles,
        invitesPerHour: $perHour,
        openInvites: $open,
        actorInvitesPerHour: $perActor,
    );
}

/** The HttpException $work throws, or null for none. */
function refused(\Closure $work): ?HttpException
{
    try {
        $work();
    } catch (HttpException $e) {
        return $e;
    }

    return null;
}

/** @return list<string> the errors under $field */
function errors(Response $response, string $field): array
{
    expect($response->status)->toBe(422, $response->body());

    return App::json($response)['errors'][$field] ?? [];
}

describe('the page', function () {
    it('shows the organization, its members and the roles, and which forms the reader may use', function () {
        $page = new AdminWorld()->page('/settings');

        expect($page['name'])->toBe('Acme')
            ->and($page['slug'])->toBe('acme')
            ->and(array_map(static fn (array $m): array => [$m['userId'], $m['name'], $m['role'], $m['you']], $page['members']))->toBe([
                ['m-1', 'Ann', 'owner', true],
                ['m-2', 'Bob', 'admin', false],
                ['m-3', 'Cy', 'member', false],
            ])
            ->and(array_column($page['roles'], 'label', 'value'))->toBe(['owner' => 'Owner', 'admin' => 'Admin', 'member' => 'Member'])
            ->and($page['canManageSettings'])->toBeTrue()
            ->and($page['canManageMembers'])->toBeTrue();
    });

    it('draws no forms for a member, and refuses someone of another tenant', function () {
        $world = new AdminWorld();
        $member = $world->page('/settings', 'm-3');

        expect($member['canManageSettings'])->toBeFalse()
            ->and($member['canManageMembers'])->toBeFalse()
            // Dee owns initech, and is nobody at acme.
            ->and($world->status('/settings', 'm-4'))->toBe(403);
    });

    it('lists only the tenant the host is for, though the reader belongs to another too', function () {
        $page = new AdminWorld()->page('/settings', 'm-1', 'globex.example.test');

        expect($page['name'])->toBe('Globex')
            ->and(array_column($page['members'], 'userId'))->toBe(['m-9', 'm-1'])
            ->and($page['canManageMembers'])->toBeFalse();
    });
});

describe('the name and the slug', function () {
    it('renames the organization, and records it', function () {
        $world = new AdminWorld();

        expect($world->act('OrganizationSettings.rename', ['name' => '  Acme Corp  '])->status)->toBe(200)
            ->and($world->tenants->profile('acme')?->name)->toBe('Acme Corp')
            ->and($world->activity->records)->toBe([['change' => 'tenant.renamed', 'tenant' => 'acme', 'actor' => 'm-1', 'details' => ['from' => 'Acme', 'to' => 'Acme Corp']]]);
    });

    it('refuses a name that is empty or too long', function (string $name) {
        expect(errors(new AdminWorld()->act('OrganizationSettings.rename', ['name' => $name]), 'name'))->not->toBe([]);
    })->with(['blank' => ['   '], 'long' => [str_repeat('a', 101)]]);

    it('refuses a name that is not UTF-8, which the control-character check cannot read (ADR 0220)', function (string $name) {
        $world = new AdminWorld();

        expect(static fn () => $world->kit->settings->rename('acme', $name, new Person('m-1', '', '')))->toThrow(ValidationException::class)
            ->and($world->tenants->profile('acme')?->name)->toBe('Acme');
    })->with([
        'a lone continuation byte, beside a bell' => ["Acme\x80\x07"],
        'a truncated sequence, beside an escape' => ["Acme \xE2\x80\x1B[31m"],
    ]);

    it('lets only a role carrying the settings permission change them', function () {
        $world = new AdminWorld();

        expect($world->act('OrganizationSettings.rename', ['name' => 'Bob Co'], 'm-2')->status)->toBe(403)
            ->and($world->act('OrganizationSettings.changeSlug', ['slug' => 'bobco'], 'm-3')->status)->toBe(403)
            ->and($world->tenants->profile('acme')?->name)->toBe('Acme');
    });

    it('moves the slug, and sends the browser to the new address through the handoff', function () {
        $world = new AdminWorld();

        $response = $world->act('OrganizationSettings.changeSlug', ['slug' => 'Acme-Corp']);

        expect($response->status)->toBe(303, $response->body())
            ->and($response->header('location'))->toBe('https://acme-corp.example.test/_handoff/start?return=%2Fsettings')
            ->and($world->tenants->tenantForSlug('acme-corp'))->toBe('acme')
            ->and($world->tenants->tenantForSlug('acme'))->toBeNull();
    });

    it('refuses a reserved slug, another tenant\'s, and one that is no label', function (string $slug, string $says) {
        $world = new AdminWorld();

        expect(errors($world->act('OrganizationSettings.changeSlug', ['slug' => $slug]), 'slug')[0])->toContain($says)
            ->and($world->tenants->profile('acme')?->slug)->toBe('acme');
    })->with([
        'admin' => ['admin', 'platform\'s own'],
        'www' => ['www', 'platform\'s own'],
        'globex\'s' => ['globex', 'Another organization'],
        'too short' => ['ab', 'lowercase letters'],
        'not a label' => ['acme_corp', 'lowercase letters'],
        'double hyphen' => ['xn--acme', 'single hyphens'],
    ]);
});

describe('invites', function () {
    it('sends one from the tenant\'s own address, to a role no wider than the inviter\'s', function () {
        $world = new AdminWorld();

        $response = $world->act('OrganizationSettings.invite', ['email' => 'Dee@Example.net', 'role' => 'admin']);

        expect($response->status)->toBe(200, $response->body())
            ->and($world->invites->sent[0]['email'])->toBe('dee@example.net')
            ->and($world->invites->sent[0]['url'])->toStartWith('https://acme.example.test/invite?token=')
            ->and($world->page('/settings')['invites'][0]['email'])->toBe('dee@example.net')
            ->and($world->activity->changes())->toBe(['tenant.member_invited']);
    });

    it('refuses an admin inviting anyone as an owner, and a member inviting at all', function () {
        $world = new AdminWorld();

        expect(errors($world->act('OrganizationSettings.invite', ['email' => 'x@example.net', 'role' => 'owner'], 'm-2'), 'role')[0])->toContain('no more than yours')
            ->and($world->act('OrganizationSettings.invite', ['email' => 'x@example.net', 'role' => 'member'], 'm-3')->status)->toBe(403)
            ->and(errors($world->act('OrganizationSettings.invite', ['email' => 'x@example.net', 'role' => 'emperor']), 'role'))->not->toBe([])
            ->and($world->invites->sent)->toBe([]);
    });

    it('sends an organization no more invites an hour than its budget, counted per organization (ADR 0220)', function () {
        $world = new AdminWorld();
        $throttles = new ArrayThrottleStore();
        $settings = budgeted($world, $throttles, perHour: 2);
        $ann = new Person('m-1', '', '');

        $world->orm->actAs('acme', static function () use ($settings, $ann): void {
            $settings->invite('acme', 'customer', 'one@example.net', 'member', $ann);
            $settings->invite('acme', 'customer', 'two@example.net', 'member', $ann);
        });
        $refused = $world->orm->actAs('acme', static fn (): ?HttpException => refused(static fn () => $settings->invite('acme', 'customer', 'three@example.net', 'member', $ann)));
        // Globex's budget is its own.
        $world->orm->actAs('globex', static fn () => $settings->invite('globex', 'customer', 'zed@example.net', 'member', new Person('m-9', '', '')));

        expect($refused?->status)->toBe(429)
            ->and($refused?->getMessage())->toContain('this hour')
            ->and(array_column($world->invites->sent, 'email'))->toBe(['one@example.net', 'two@example.net', 'zed@example.net']);
    });

    // One person may administer many organizations, each with a budget of
    // its own: their own budget holds what they send across all of them,
    // and spent, refuses them before it touches the organization's.
    it('sends one person no more invites an hour than their own budget, counted per person beside the organization\'s (ADR 0220)', function () {
        $world = new AdminWorld();
        $throttles = new ArrayThrottleStore();
        $settings = budgeted($world, $throttles, perHour: 3, perActor: 2);
        $ann = new Person('m-1', '', '');

        $world->orm->actAs('acme', static function () use ($settings, $ann): void {
            $settings->invite('acme', 'customer', 'one@example.net', 'member', $ann);
            $settings->invite('acme', 'customer', 'two@example.net', 'member', $ann);
        });
        $refused = $world->orm->actAs('acme', static fn (): ?HttpException => refused(static fn () => $settings->invite('acme', 'customer', 'three@example.net', 'member', $ann)));
        $again = $world->orm->actAs('acme', static fn (): ?HttpException => refused(static fn () => $settings->invite('acme', 'customer', 'four@example.net', 'member', $ann)));
        // Bob's budget is his own, and Ann's refusals spent none of acme's.
        $world->orm->actAs('acme', static fn () => $settings->invite('acme', 'customer', 'bob@example.org', 'member', new Person('m-2', '', '')));

        expect($refused?->status)->toBe(429)
            ->and($refused?->getMessage())->toContain('You have sent')
            ->and($again?->status)->toBe(429)
            ->and(array_column($world->invites->sent, 'email'))->toBe(['one@example.net', 'two@example.net', 'bob@example.org']);
    });

    it('keeps no more invites open in an organization than its budget, however slowly they are sent (ADR 0220)', function () {
        $world = new AdminWorld();
        $settings = budgeted($world, new ArrayThrottleStore(), open: 2);
        $ann = new Person('m-1', '', '');

        $world->orm->actAs('acme', static function () use ($settings, $ann): void {
            $settings->invite('acme', 'customer', 'one@example.net', 'member', $ann);
            $settings->invite('acme', 'customer', 'two@example.net', 'member', $ann);
        });
        $refused = $world->orm->actAs('acme', static function () use ($settings, $ann): ?string {
            try {
                $settings->invite('acme', 'customer', 'three@example.net', 'member', $ann);
            } catch (ValidationException $e) {
                return $e->errors['email'][0] ?? null;
            }

            return null;
        });

        expect($refused)->toContain('revoke one')
            ->and($world->invites->sent)->toHaveCount(2);
    });

    it('refuses an impersonator, or an API token, accepting an invite by its emailed link (ADR 0220)', function () {
        $world = new AdminWorld();
        $world->act('OrganizationSettings.invite', ['email' => 'dee@example.net', 'role' => 'admin']);
        $token = $world->invites->lastToken();

        expect($world->act('OrganizationInvitation.accept', ['token' => $token], 'm-4', headers: ['x-impersonator' => 'm-1'])->status)->toBe(403)
            ->and($world->act('OrganizationInvitation.accept', ['token' => $token], 'm-4', headers: ['x-api-token' => 'tok-1'])->status)->toBe(403)
            ->and($world->members->roleOf('acme', 'customer', 'm-4'))->toBeNull()
            // Still open for Dee, signed in as herself.
            ->and($world->act('OrganizationInvitation.accept', ['token' => $token], 'm-4')->status)->toBe(303);
    });

    it('revokes an open invite, and not one of another tenant\'s', function () {
        $world = new AdminWorld();
        $world->act('OrganizationSettings.invite', ['email' => 'dee@example.net', 'role' => 'member']);
        $acmeInvite = $world->page('/settings')['invites'][0]['id'];
        $world->act('OrganizationSettings.invite', ['email' => 'zed@example.net', 'role' => 'member'], 'm-9', 'globex.example.test');
        $globexInvite = $world->page('/settings', 'm-9', 'globex.example.test')['invites'][0]['id'];

        expect($world->act('OrganizationSettings.revokeInvite', ['invite' => $globexInvite])->status)->toBe(404)
            ->and($world->page('/settings', 'm-9', 'globex.example.test')['invites'])->toHaveCount(1)
            ->and($world->act('OrganizationSettings.revokeInvite', ['invite' => $acmeInvite])->status)->toBe(200)
            ->and($world->page('/settings')['invites'])->toBe([]);
    });

    it('is accepted by the account of its address, signed in, into its tenant and role', function () {
        $world = new AdminWorld();
        $world->act('OrganizationSettings.invite', ['email' => 'dee@example.net', 'role' => 'admin']);
        $token = $world->invites->lastToken();

        $page = $world->page('/invite', 'm-4', query: ['token' => $token]);
        $accepted = $world->act('OrganizationInvitation.accept', ['token' => $token], 'm-4');

        expect($page)->toBe(['open' => true, 'organization' => 'Acme', 'email' => 'dee@example.net', 'role' => 'Admin', 'accountExists' => true, 'signedInAsInvitee' => true, 'acceptAction' => 'OrganizationInvitation.accept', 'token' => $token])
            ->and($accepted->status)->toBe(303, $accepted->body())
            ->and($world->members->roleOf('acme', 'customer', 'm-4'))->toBe('admin')
            ->and($world->members->roleOf('initech', 'customer', 'm-4'))->toBe('owner')
            ->and($world->activity->changes())->toBe(['tenant.member_invited', 'tenant.invite_accepted'])
            // Once only.
            ->and(errors($world->act('OrganizationInvitation.accept', ['token' => $token], 'm-4'), 'token')[0])->toContain('expired');
    });

    it('joins nobody when opened by anyone else: a forwarded link, another account, nobody signed in', function () {
        $world = new AdminWorld();
        $world->act('OrganizationSettings.invite', ['email' => 'dee@example.net', 'role' => 'admin']);
        $token = $world->invites->lastToken();

        $page = $world->page('/invite', 'nobody', query: ['token' => $token]);

        expect($page['open'])->toBeTrue()
            ->and($page['signedInAsInvitee'])->toBeFalse()
            ->and(errors($world->act('OrganizationInvitation.accept', ['token' => $token], 'm-9'), 'token')[0])->toContain('sign in as that account')
            ->and($world->act('OrganizationInvitation.accept', ['token' => $token], 'nobody')->status)->toBe(401)
            ->and($world->members->roleOf('acme', 'customer', 'm-9'))->toBeNull()
            ->and($world->members->roleOf('acme', 'customer', 'm-4'))->toBeNull()
            // Still open for Dee.
            ->and($world->act('OrganizationInvitation.accept', ['token' => $token], 'm-4')->status)->toBe(303);
    });

    it('opens on no other tenant\'s host', function () {
        $world = new AdminWorld();
        $world->act('OrganizationSettings.invite', ['email' => 'dee@example.net', 'role' => 'admin']);
        $token = $world->invites->lastToken();

        expect($world->page('/invite', 'nobody', 'globex.example.test', ['token' => $token])['open'])->toBeFalse()
            ->and(errors($world->act('OrganizationInvitation.accept', ['token' => $token], 'm-4', 'globex.example.test'), 'token'))->not->toBe([])
            ->and($world->members->roleOf('globex', 'customer', 'm-4'))->toBeNull();
    });

    it('waits, still open, for an address with no account yet', function () {
        $world = new AdminWorld();
        $world->act('OrganizationSettings.invite', ['email' => 'newcomer@example.net', 'role' => 'member']);
        $token = $world->invites->lastToken();

        expect(errors($world->act('OrganizationInvitation.accept', ['token' => $token], 'm-3'), 'token')[0])->toContain('Create an account with newcomer@example.net')
            ->and($world->page('/invite', 'nobody', query: ['token' => $token]))->toMatchArray(['open' => true, 'accountExists' => false]);
    });

    it('is refused once its inviter may no longer send it', function () {
        $world = new AdminWorld();
        $world->act('OrganizationSettings.invite', ['email' => 'dee@example.net', 'role' => 'admin'], 'm-2');
        $token = $world->invites->lastToken();
        $world->members->changeRole('acme', 'customer', 'm-2', 'member');

        expect($world->page('/invite', 'nobody', query: ['token' => $token])['open'])->toBeFalse();
    });

    it('is made and mailed by the queued job, as the tenant that sent it and no other', function () {
        $world = new AdminWorld();
        $transport = new ArrayTransport();
        $renderer = new class implements MailRenderer {
            public function render(string $template, array $props, ?LinkPolicy $links = null): RenderedMail
            {
                return new RenderedMail("<p><a href=\"{$props['url']}\">Join {$props['organization']}</a></p>", "Join {$props['organization']}: {$props['url']}");
            }
        };
        $mailer = new Mailer($transport, $renderer, new Address('team@example.test'));
        $job = new SendTenantInvite('m-1', 'dee@example.net', 'acme', 'customer', 'member');

        $world->orm->actAs('globex', static fn () => $job->handle($world->orm, $world->kit->invitations, $world->people, $world->kit->links, new InviteMails(), $mailer, $world->tenants));
        $sentAsGlobex = \count($transport->sent);
        $world->orm->actAs('acme', static fn () => $job->handle($world->orm, $world->kit->invitations, $world->people, $world->kit->links, new InviteMails(), $mailer, $world->tenants));

        expect($sentAsGlobex)->toBe(0)
            ->and($transport->sent)->toHaveCount(1)
            ->and($transport->sent[0]->subject)->toBe('Join Acme')
            ->and($transport->sent[0]->text)->toContain('https://acme.example.test/invite?token=');
    });
});

describe('members', function () {
    it('changes a member\'s role, and removes one', function () {
        $world = new AdminWorld();

        expect($world->act('OrganizationSettings.changeRole', ['member' => 'm-3', 'role' => 'admin'])->status)->toBe(200)
            ->and($world->members->roleOf('acme', 'customer', 'm-3'))->toBe('admin')
            ->and($world->act('OrganizationSettings.removeMember', ['member' => 'm-3'])->status)->toBe(200)
            ->and($world->members->roleOf('acme', 'customer', 'm-3'))->toBeNull()
            ->and($world->activity->changes())->toBe(['tenant.role_changed', 'tenant.member_removed']);
    });

    it('refuses an admin touching the owner, or making anyone an owner', function () {
        $world = new AdminWorld();

        expect(errors($world->act('OrganizationSettings.changeRole', ['member' => 'm-1', 'role' => 'member'], 'm-2'), 'member'))->not->toBe([])
            ->and(errors($world->act('OrganizationSettings.removeMember', ['member' => 'm-1'], 'm-2'), 'member'))->not->toBe([])
            ->and(errors($world->act('OrganizationSettings.changeRole', ['member' => 'm-3', 'role' => 'owner'], 'm-2'), 'role'))->not->toBe([])
            ->and($world->members->roleOf('acme', 'customer', 'm-1'))->toBe('owner');
    });

    it('never leaves nobody able to manage members', function () {
        $world = new AdminWorld();
        $world->act('OrganizationSettings.removeMember', ['member' => 'm-2']);

        expect(errors($world->act('OrganizationSettings.changeRole', ['member' => 'm-1', 'role' => 'member']), 'member')[0])->toContain('Someone must be left')
            ->and(errors($world->act('OrganizationSettings.removeMember', ['member' => 'm-1']), 'member'))->not->toBe([])
            ->and($world->members->roleOf('acme', 'customer', 'm-1'))->toBe('owner');
    });

    it('finds no member of another tenant from this one', function () {
        $world = new AdminWorld();

        // Eve owns globex; on acme's host she is nobody.
        expect($world->act('OrganizationSettings.removeMember', ['member' => 'm-9'])->status)->toBe(404)
            ->and($world->act('OrganizationSettings.changeRole', ['member' => 'm-9', 'role' => 'member'])->status)->toBe(404)
            ->and($world->members->roleOf('globex', 'customer', 'm-9'))->toBe('owner');
    });
});

describe('the audit log', function () {
    it('records each change in the tenant\'s own log, in the change\'s transaction', function () {
        $world = new AdminWorld(audited: true);
        $world->act('OrganizationSettings.rename', ['name' => 'Acme Corp']);
        $world->act('OrganizationSettings.rename', ['name' => ''], 'm-1');

        $acme = $world->orm->actAs('acme', static fn (): array => $world->audit?->trail()->recent() ?? []);
        $globex = $world->orm->actAs('globex', static fn (): array => $world->audit?->trail()->recent() ?? []);

        expect(array_map(static fn ($e): string => $e->event, $acme))->toBe(['tenant.renamed'])
            ->and($acme[0]->data)->toBe(['tenant' => 'acme', 'from' => 'Acme', 'to' => 'Acme Corp'])
            ->and($acme[0]->actor->id)->toBe('m-1')
            ->and($globex)->toBe([]);
    });
});

describe('the kit\'s registrations', function () {
    it('binds the memberships it was given and its user lookup, which the policy and the invite job are built with', function () {
        $world = new AdminWorld();
        $container = $world->kit->register(new \Tinvar\Container\Container());

        expect($container->get(\Tinvar\Auth\Memberships::class))->toBe($world->members)
            ->and($container->get(\Tinvar\Auth\TenantMemberships::class))->toBe($world->members)
            ->and($container->get(\Tinvar\Auth\UserLookup::class))->toBe($world->people);
    });

    it('leaves a binding the app made already as the app made it', function () {
        $world = new AdminWorld();
        $own = new \Tinvar\Tenancy\SelfService\DatabaseTenantMembers($world->orm, $world->people);
        $lookup = new \Tinvar\Tests\Tenancy\SelfService\Fixtures\People();
        $container = new \Tinvar\Container\Container()
            ->instance(\Tinvar\Auth\Memberships::class, $own)
            ->bind(\Tinvar\Auth\TenantMemberships::class, static fn (): object => $own)
            ->instance(\Tinvar\Auth\UserLookup::class, $lookup);
        $world->kit->register($container);

        expect($container->get(\Tinvar\Auth\Memberships::class))->toBe($own)
            ->and($container->get(\Tinvar\Auth\TenantMemberships::class))->toBe($own)
            ->and($container->get(\Tinvar\Auth\UserLookup::class))->toBe($lookup);
    });
});
