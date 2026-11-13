<?php

declare(strict_types=1);

/**
 * Invites on a host that serves every tenant (ADR 0182), through the real
 * Kernel with the accounts kit's sessions: an invite sent from acme's
 * settings is accepted on the one host by its address's account whatever
 * tenant -- none, or another -- its session holds; it joins acme alone, as
 * acme, and switches the session there. A link edited to name another
 * tenant, used twice, opened by anyone else, or made for a tenant with a
 * host of its own opens nothing here.
 */

namespace Tinvar\Tests\Tenancy\OneHostInvites;

use Tinvar\Http\Response;
use Tinvar\Identity\ArrayEmailLinkStore;
use Tinvar\Identity\CanonicalOrigins;
use Tinvar\Identity\EmailLinks;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Signer;
use Tinvar\Tenancy\SelfService\DatabaseTenants;
use Tinvar\Tenancy\SelfService\TenantSelfService;
use Tinvar\Tests\Accounts\Fixtures\MemberApp;
use Tinvar\Tests\Accounts\Fixtures\Members;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Tenancy\Fixtures\OneHostInvites\App;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/databases.php';
require_once __DIR__ . '/Fixtures/one_host_invites_app.php';

/** @return list<string> the errors under $field */
function errors(Response $response, string $field): array
{
    expect($response->status)->toBe(422, $response->body());

    return App::errors($response, $field);
}

/** Ann, acme's owner, in acme; Dee, signed in, in no tenant; and the invite Ann sent Dee. */
function invited(App $app, string $role = 'member'): array
{
    $ann = $app->person('ann@example.com');
    $app->members->grant('acme', 'customer', $app->idOf('ann@example.com'), 'owner');
    $ann = $app->in('acme', $ann);
    $dee = $app->person('dee@example.com');

    $sent = $app->act('OrganizationSettings.invite', ['email' => 'dee@example.com', 'role' => $role], $ann);
    expect($sent->status)->toBe(200, $sent->body());

    return ['ann' => $ann, 'dee' => $dee, 'token' => $app->invites->lastToken()];
}

/** $token with its tenant claim edited to $tenant, its MAC left as it was. */
function naming(string $token, string $tenant): string
{
    [$version, $payload, $mac] = explode('.', $token);
    $claims = json_decode((string) Signer::decode($payload), true, flags: JSON_THROW_ON_ERROR);
    \assert(\is_array($claims));

    return $version . '.' . Signer::encode((string) json_encode([...$claims, 'y' => $tenant])) . '.' . $mac;
}

/** @param \Closure(App): void $test */
function onEach(string $driver, \Closure $test): void
{
    if ($driver === 'sqlite') {
        $test(new App());

        return;
    }
    Databases::inScratchSchema($driver, static function (\PDO $pdo) use ($test, $driver): void {
        if ($driver !== 'mysql') {
            $test(new App(new PdoConnection($pdo, "one-host-invites-{$driver}")));

            return;
        }
        // The ORM quotes names as standard SQL does, which MySQL reads so
        // with ANSI_QUOTES: on for the test, and put back for the next.
        $mode = $pdo->query('SELECT @@SESSION.sql_mode')?->fetchColumn();
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
        try {
            $test(new App(new PdoConnection($pdo, 'one-host-invites-mysql')));
        } finally {
            $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote(\is_string($mode) ? $mode : ''));
        }
    });
}

dataset('drivers', static function (): array {
    $drivers = ['sqlite' => ['sqlite']];
    if (Databases::postgresMissing() === null) {
        $drivers['pgsql'] = ['pgsql'];
    }
    if (Databases::mysqlMissing() === null) {
        $drivers['mysql'] = ['mysql'];
    }

    return $drivers;
});

it('is accepted on the one host by its address\'s account with no tenant chosen, into its tenant alone, and switches the session there', function (string $driver) {
    onEach($driver, static function (App $app): void {
        ['dee' => $dee, 'token' => $token] = invited($app);
        $deeId = $app->idOf('dee@example.com');

        expect($app->invites->urls[0])->toStartWith('https://app.test/invite?token=');

        $page = $app->get('/invite', $dee, ['token' => $token]);
        expect($page->status)->toBe(200, $page->body())
            ->and(App::json($page))->toMatchArray(['open' => true, 'organization' => 'Acme', 'email' => 'dee@example.com', 'accountExists' => true, 'signedInAsInvitee' => true, 'token' => $token]);

        $accepted = $app->act('OrganizationInvitation.accept', ['token' => $token], $dee);
        $joined = $app->cookieOf($accepted);
        expect($accepted->status)->toBe(303, $accepted->body())
            ->and($accepted->header('location'))->toBe('/')
            ->and($app->members->roleOf('acme', 'customer', $deeId))->toBe('member')
            ->and($app->members->roleOf('globex', 'customer', $deeId))->toBeNull()
            ->and($app->members->roleOf('initech', 'customer', $deeId))->toBeNull()
            // Recorded as acme, in acme.
            ->and($app->activity->records[1] ?? null)->toBe(['change' => 'tenant.invite_accepted', 'tenant' => 'acme', 'runningAs' => 'acme'])
            // A new session, running as acme: the old id signs nobody in.
            ->and($joined)->toBeString()->not->toBe($dee);
        \assert(\is_string($joined));
        expect(App::json($app->get('/whoami', $joined)))->toBe(['tenant' => 'acme'])
            ->and($app->get('/whoami', $dee)->status)->toBe(401)
            ->and($app->orm->tenant())->toBe('');

        // Once only.
        expect(errors($app->act('OrganizationInvitation.accept', ['token' => $token], $joined), 'token')[0])->toContain('expired');
    });
})->with('drivers');

// The session's tenant is never the invite's: the action runs as the one
// the link names, and grants nothing in the one the session holds.
it('is accepted by a session running as another tenant, as the invite\'s tenant and not the session\'s', function () {
    $app = new App();
    ['token' => $token] = invited($app, 'admin');
    $deeId = $app->idOf('dee@example.com');
    $app->members->grant('initech', 'customer', $deeId, 'owner');
    $dee = $app->in('initech', $app->signIn('dee@example.com'));
    expect(App::json($app->get('/whoami', $dee)))->toBe(['tenant' => 'initech']);

    expect(App::json($app->get('/invite', $dee, ['token' => $token])))->toMatchArray(['open' => true, 'organization' => 'Acme', 'role' => 'Admin']);
    $accepted = $app->act('OrganizationInvitation.accept', ['token' => $token], $dee);
    $joined = $app->cookieOf($accepted);

    expect($accepted->status)->toBe(303, $accepted->body())
        ->and($app->members->roleOf('acme', 'customer', $deeId))->toBe('admin')
        ->and($app->members->roleOf('initech', 'customer', $deeId))->toBe('owner')
        ->and(array_values(array_filter($app->activity->records, static fn (array $r): bool => $r['change'] === 'tenant.invite_accepted')))
        ->toBe([['change' => 'tenant.invite_accepted', 'tenant' => 'acme', 'runningAs' => 'acme']]);
    \assert(\is_string($joined));
    expect(App::json($app->get('/whoami', $joined)))->toBe(['tenant' => 'acme']);
});

it('opens nothing for a link edited to name another tenant, and grants nothing there', function () {
    $app = new App();
    ['dee' => $dee, 'token' => $token] = invited($app);
    $deeId = $app->idOf('dee@example.com');

    foreach (['globex', 'initech', ''] as $other) {
        $swapped = naming($token, $other);
        expect(App::json($app->get('/invite', $dee, ['token' => $swapped]))['open'])->toBeFalse()
            ->and(errors($app->act('OrganizationInvitation.accept', ['token' => $swapped], $dee), 'token'))->not->toBe([]);
    }
    // Nor does a tenant named beside the token in the query.
    expect(App::json($app->get('/invite', $dee, ['token' => 'not-a-token', 'tenant' => 'acme']))['open'])->toBeFalse();

    expect($app->members->roleOf('globex', 'customer', $deeId))->toBeNull()
        ->and($app->members->roleOf('initech', 'customer', $deeId))->toBeNull()
        ->and($app->members->roleOf('acme', 'customer', $deeId))->toBeNull()
        // The real one is still open.
        ->and($app->act('OrganizationInvitation.accept', ['token' => $token], $dee)->status)->toBe(303);
});

it('joins nobody when accepted by another account, or nobody signed in', function () {
    $app = new App();
    ['token' => $token] = invited($app);
    $eve = $app->person('eve@example.com');

    expect(App::json($app->get('/invite', $app->nobody(), ['token' => $token])))->toMatchArray(['open' => true, 'signedInAsInvitee' => false])
        ->and(errors($app->act('OrganizationInvitation.accept', ['token' => $token], $eve), 'token')[0])->toContain('sign in as that account')
        ->and($app->act('OrganizationInvitation.accept', ['token' => $token], $app->nobody())->status)->toBe(401)
        ->and($app->members->roleOf('acme', 'customer', $app->idOf('eve@example.com')))->toBeNull()
        ->and($app->members->roleOf('acme', 'customer', $app->idOf('dee@example.com')))->toBeNull();
});

it('opens nothing here for a tenant with a host of its own, whose invites land there', function () {
    $app = new App();
    $owner = $app->person('ann@example.com');
    $app->members->grant('umbrella', 'customer', $app->idOf('ann@example.com'), 'owner');
    $dee = $app->person('dee@example.com');
    $sent = $app->act('OrganizationSettings.invite', ['email' => 'dee@example.com', 'role' => 'member'], $app->in('umbrella', $owner));
    expect($sent->status)->toBe(200, $sent->body())
        ->and($app->invites->urls[0])->toStartWith('https://umbrella.app.test/invite?token=');
    $token = $app->invites->lastToken();

    expect(App::json($app->get('/invite', $dee, ['token' => $token]))['open'])->toBeFalse()
        ->and(errors($app->act('OrganizationInvitation.accept', ['token' => $token], $dee), 'token'))->not->toBe([])
        ->and($app->members->roleOf('umbrella', 'customer', $app->idOf('dee@example.com')))->toBeNull();
});

it('is refused once revoked, or once its inviter may no longer send it', function () {
    $app = new App();
    ['ann' => $ann, 'dee' => $dee, 'token' => $token] = invited($app, 'admin');
    $app->members->changeRole('acme', 'customer', $app->idOf('ann@example.com'), 'member');

    expect(App::json($app->get('/invite', $dee, ['token' => $token]))['open'])->toBeFalse()
        ->and(errors($app->act('OrganizationInvitation.accept', ['token' => $token], $dee), 'token'))->not->toBe([]);

    $app->members->changeRole('acme', 'customer', $app->idOf('ann@example.com'), 'owner');
    $id = $app->kit->invitations->pending('acme', 'customer')[0]->id;
    expect($app->act('OrganizationSettings.revokeInvite', ['invite' => $id], $ann)->status)->toBe(200)
        ->and(App::json($app->get('/invite', $dee, ['token' => $token]))['open'])->toBeFalse();
});

// A one-host application in development is served on plain http on
// localhost, http://dash.localhost:8080: the kit is built there, with the
// canonical origin, the emailed links and the kit all told so.
it('builds and opens invites on a development host of plain http on localhost', function () {
    $app = new App();
    $people = new Members($app->orm);
    $ann = $people->create('ann@example.com', 'Ann', password_hash('a passphrase long enough', PASSWORD_BCRYPT, ['cost' => 4]));
    $app->members->grant('acme', 'customer', $ann->authIdentifier(), 'owner');
    $links = EmailLinks::fromKey(MemberApp::KEY, new ArrayEmailLinkStore(), $app->accounts->kit->lookup, localHttp: true);
    $kit = new TenantSelfService(
        orm: $app->orm,
        auth: $app->accounts->auth,
        memberships: $app->members,
        realms: new Realms(Realm::platform('http://dash.localhost:8080')),
        profiles: new DatabaseTenants($app->orm->connection()),
        members: $app->members,
        emailLinks: $links,
        users: $app->accounts->kit->lookup,
        canonical: new CanonicalOrigins('http://dash.localhost:8080', localHttp: true),
        invites: $app->invites,
        throttles: new \Tinvar\Http\ArrayThrottleStore(),
        localHttp: true,
    );

    $url = $kit->invitations->invite($ann, 'dee@example.com', 'acme', 'customer', 'member', $kit->links->originFor('acme'))['url'];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $token = (string) $query['token'];

    expect($kit->links->originFor('acme'))->toBe('http://dash.localhost:8080')
        ->and($kit->links->hasOwnHost('acme'))->toBeFalse()
        ->and($url)->toStartWith('http://dash.localhost:8080/invite?token=')
        ->and($kit->invitations->tenantOf($token))->toBe('acme')
        ->and($kit->invitations->open($token, $kit->links->originFor('acme'), 'acme')?->email)->toBe('dee@example.com');
});
