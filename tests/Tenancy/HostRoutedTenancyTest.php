<?php

declare(strict_types=1);

/**
 * Tenancy where the application routes by host (ADR 0149): tenants on
 * `{tenant}.example.test` and on a custom domain, the platform on the apex
 * and `www.`, staff on `admin.`, all behind a proxy pinned with a secret --
 * driven through the Kernel, request after request, as a worker answers
 * them.
 *
 * The tenant source and the router read one host -- the one Domains
 * resolved, through the pinned proxy's X-Forwarded-Host when it proves
 * itself and the Host header otherwise -- so a route's `{tenant}` and the
 * tenant a request runs as cannot be made to disagree, and a host the
 * platform keeps for itself is no tenant's, whatever a tenant named its
 * slug.
 */

namespace Tinvar\Tests\Tenancy\HostRoutedTenancy;

use Tinvar\Http\TrustedProxies;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\RowLevelSecurity;
use Tinvar\Render\RouteToken;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Tenancy\Fixtures\Domains\App;
use Tinvar\Tests\Tenancy\Fixtures\Domains\CachedNoteBodies;
use Tinvar\Tests\Tenancy\Fixtures\Domains\Database;
use Tinvar\Tests\Tenancy\Fixtures\Domains\Note;
use Tinvar\Tests\Tenancy\Fixtures\Domains\SessionTenant;
use Tinvar\Tests\Tenancy\Fixtures\Domains\SignIn;

use const Tinvar\Tests\Tenancy\Fixtures\Domains\PROXY;
use const Tinvar\Tests\Tenancy\Fixtures\Domains\PROXY_SECRET;
use const Tinvar\Tests\Tenancy\Fixtures\Domains\ROOT;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/databases.php';
require_once __DIR__ . '/Fixtures/domains_app.php';

function app(?Database $database = null, array $global = [SignIn::class]): App
{
    return new App(($database ?? new Database())->connection, $global);
}

/** @return array<string, mixed> the page's data, or a failed expectation naming the response */
function page(App $app, string $path, string $host, array $headers = [], ?string $peer = null): array
{
    $response = $app->get($path, $host, $headers, $peer);
    expect($response->status)->toBe(200, $response->body());

    return App::json($response)['page'];
}

function status(App $app, string $path, string $host, array $headers = [], ?string $peer = null): int
{
    return $app->get($path, $host, $headers, $peer)->status;
}

describe('a subdomain', function () {
    it('runs a page on {tenant}.example.test as that host\'s tenant, its label a value beside it', function () {
        $app = app();

        expect(page($app, '/notes', 'acme.example.test'))->toBe(['tenant' => 'acme', 'label' => 'acme', 'host' => 'acme.example.test', 'bodies' => ['acme-note-1', 'acme-note-2']])
            ->and(page($app, '/notes', 'globex.example.test')['bodies'])->toBe(['globex-note-1', 'globex-note-2']);
    });

    it('reads the host however it is spelled: case, a trailing dot, the port served', function (string $host) {
        expect(page(app(), '/notes', $host)['tenant'])->toBe('acme');
    })->with(['ACME.Example.TEST', 'acme.example.test.', 'acme.example.test:443', 'Acme.Example.Test.:443']);

    it('refuses a port the application is not served on', function () {
        expect(status(app(), '/notes', 'acme.example.test:8443'))->toBe(421);
    });

    it('takes a punycode label as the slug it spells, and refuses one that is not a host name', function () {
        $database = new Database();
        $app = app($database);
        $app->directory->slugs['xn--bcher-kva'] = 'acme';

        expect(page($app, '/notes', 'xn--bcher-kva.example.test')['tenant'])->toBe('acme')
            ->and(status($app, '/notes', 'bücher.example.test'))->toBe(421);
    });

    it('answers a slug nobody holds with a 404, before any loader runs', function () {
        $response = app()->get('/notes', 'nobody.example.test');

        expect($response->status)->toBe(404)
            ->and(App::json($response)['error']['code'])->toBe('tenant_unknown');
    });

    it('answers a suspended tenant\'s subdomain as one nobody holds', function () {
        $response = app()->get('/notes', 'umbrella.example.test');

        expect($response->status)->toBe(404)
            ->and(App::json($response)['error']['code'])->toBe('tenant_unknown');
    });

    it('answers a deleted tenant\'s subdomain the same way, from the next request on', function () {
        $app = app();
        expect(page($app, '/notes', 'globex.example.test')['tenant'])->toBe('globex');

        unset($app->directory->slugs['globex']);

        expect(status($app, '/notes', 'globex.example.test'))->toBe(404);
    });

    it('refuses two labels in front of the domain, which no wildcard certificate covers', function () {
        expect(status(app(), '/notes', 'x.acme.example.test'))->toBe(421);
    });

    it('binds a route model in the host\'s tenant, so another tenant\'s id is not found', function () {
        $database = new Database();
        $app = app($database);

        expect(page($app, '/notes/' . $database->notes['acme'][0], 'acme.example.test'))->toBe(['body' => 'acme-note-1'])
            ->and(status($app, '/notes/' . $database->notes['globex'][0], 'acme.example.test'))->toBe(404)
            ->and(status($app, '/notes/' . $database->notes['initech'][0], 'acme.example.test'))->toBe(404);
    });
});

describe('a custom domain', function () {
    it('runs as the tenant it is mapped to, on the routes of {tenant}.{root}', function () {
        expect(page(app(), '/notes', 'initech.io'))->toBe(['tenant' => 'initech', 'label' => 'initech', 'host' => 'initech.io', 'bodies' => ['initech-note-1', 'initech-note-2']]);
    });

    it('reads it however it is spelled', function () {
        expect(page(app(), '/notes', 'INITECH.IO.')['tenant'])->toBe('initech');
    });

    it('refuses a domain mapped to no tenant before anything runs for it', function () {
        expect(status(app(), '/notes', 'unknown.io'))->toBe(421);
    });

    it('stops answering a suspended tenant\'s domain', function () {
        $app = app();
        $app->directory->suspended[] = 'initech';

        expect(status($app, '/notes', 'initech.io'))->toBe(421);
    });

    it('finds none of another tenant\'s rows by id', function () {
        $database = new Database();

        expect(status(app($database), '/notes/' . $database->notes['acme'][0], 'initech.io'))->toBe(404);
    });
});

describe('the platform\'s own hosts', function () {
    it('serves the apex as the platform\'s, with no tenant', function () {
        expect(page(app(), '/', 'example.test'))->toBe(['text' => 'platform'])
            ->and(page(app(), '/', 'www.example.test'))->toBe(['text' => 'platform']);
    });

    it('places a request on the apex in no tenant', function () {
        $response = app()->get('/notes', 'example.test');

        expect($response->status)->toBe(404)
            ->and(App::json($response)['error']['code'])->toBe('tenant_unknown');
    });

    // A slug is chosen at sign-up. One that spells a host the platform keeps
    // -- www, answered as the apex; admin, the staff's -- names that host's
    // tenant nowhere: the host is the platform's, and an action or a query
    // answered there runs as nobody.
    it('places a request on www, the apex\'s alias, in no tenant, whoever holds the slug www', function () {
        $app = app();

        expect(status($app, '/notes', 'www.example.test'))->toBe(404)
            ->and($app->post('/_query/notes.bodies', 'www.example.test', ['args' => new \stdClass()])->status)->toBe(404);
    });

    it('places a call on the staff\'s host in no tenant, whoever holds the slug admin', function () {
        $database = new Database();
        $app = app($database);

        expect($app->post('/_query/notes.bodies', 'admin.example.test', ['args' => new \stdClass()])->status)->toBe(404)
            ->and($app->post('/_action/NoteActions.touch', 'admin.example.test', ['note' => $database->notes['tenant-admin'][0]])->status)->toBe(404)
            ->and(page($app, '/', 'admin.example.test'))->toBe(['text' => 'staff']);
    });

    it('serves a tenant\'s pages on none of the platform\'s hosts', function () {
        expect(status(app(), '/raw', 'admin.example.test'))->toBe(404)
            ->and(status(app(), '/raw', 'example.test'))->toBe(404);
    });
});

describe('behind a pinned proxy', function () {
    it('reads the tenant from X-Forwarded-Host when the proxy proves itself with its secret', function () {
        $headers = ['x-forwarded-host' => 'globex.example.test', TrustedProxies::SECRET_HEADER => PROXY_SECRET];

        expect(page(app(), '/notes', 'internal.svc', $headers, PROXY)['tenant'])->toBe('globex');
    });

    it('reads the Host header, not X-Forwarded-Host, from a peer in the range without the secret', function () {
        $app = app();
        $forged = ['x-forwarded-host' => 'globex.example.test'];
        $wrong = [...$forged, TrustedProxies::SECRET_HEADER => 'not-the-secret-but-long-enough-0123456789'];

        expect(page($app, '/notes', 'acme.example.test', $forged, PROXY)['tenant'])->toBe('acme')
            ->and(page($app, '/notes', 'acme.example.test', $wrong, PROXY)['tenant'])->toBe('acme');
    });

    it('reads the Host header from a peer outside the range, whatever it sends', function () {
        $headers = ['x-forwarded-host' => 'globex.example.test', TrustedProxies::SECRET_HEADER => PROXY_SECRET];

        expect(page(app(), '/notes', 'acme.example.test', $headers, '203.0.113.9')['tenant'])->toBe('acme');
    });

    it('takes the proxy\'s empty X-Forwarded-Host as no host, never the Host behind it', function () {
        $headers = ['x-forwarded-host' => '', TrustedProxies::SECRET_HEADER => PROXY_SECRET];

        expect(status(app(), '/notes', 'acme.example.test', $headers, PROXY))->toBe(421);
    });
});

describe('a route on a {tenant} host', function () {
    it('is refused when the Kernel is built unless it reads its tenant from the host', function (array $routes, array $actions, string $where, string $from) {
        expect(fn () => new App(new Database()->connection, routes: $routes, actions: $actions))
            ->toThrow(\InvalidArgumentException::class, "{$where} is on {tenant}.{root}, whose {tenant} names the tenant, and it reads its tenant from {$from}");
    })->with([
        'a page reading none, as the session\'s tenant' => [[\Tinvar\Tests\Tenancy\Fixtures\Domains\LeakyNotes::class], [], 'LeakyNotes', 'nowhere, so it runs as the tenant the session holds'],
        'a page reading a signed link' => [[\Tinvar\Tests\Tenancy\Fixtures\Domains\SignedNotes::class], [], 'SignedNotes', 'Tinvar\\Tenancy\\Signature'],
        'an action reading none' => [[], [\Tinvar\Tests\Tenancy\Fixtures\Domains\LeakyActions::class], 'LeakyActions::leak()', 'nowhere'],
    ]);

    it('says how to fix it', function () {
        expect(fn () => new App(new Database()->connection, routes: [\Tinvar\Tests\Tenancy\Fixtures\Domains\LeakyNotes::class]))
            ->toThrow(\InvalidArgumentException::class, 'Declare #[TenantFrom(Host::class)] (tinvar/tenancy) on it or a layout above it');
    });

    it('is reported by tinvar check', function () {
        $domains = new \Tinvar\Routing\Domains(['root' => ROOT]);
        $leaky = \Tinvar\Console\AppConsole::hostViolations([\Tinvar\Tests\Tenancy\Fixtures\Domains\LeakyNotes::class], [], [], $domains);
        $fine = \Tinvar\Console\AppConsole::hostViolations([\Tinvar\Tests\Tenancy\Fixtures\Domains\TenantNotes::class], [], [], $domains);

        expect($leaky)->toHaveCount(1)
            ->and($leaky[0]->rule)->toBe(\Tinvar\Console\AppConsole::TENANT_HOST_READS_HOST)
            ->and($leaky[0]->where)->toBe(\Tinvar\Tests\Tenancy\Fixtures\Domains\LeakyNotes::class)
            ->and($leaky[0]->message)->toContain('Declare #[TenantFrom(Host::class)]')
            ->and($fine)->toBe([]);
    });

    it('is built when it reads its tenant from the host', function () {
        expect(app()->kernel)->toBeInstanceOf(\Tinvar\Http\Kernel::class);
    });
});

describe('a session\'s tenant', function () {
    it('goes on on its own host, and is refused on another tenant\'s', function () {
        $app = app(global: [SignIn::class, SessionTenant::class]);

        expect(page($app, '/notes', 'acme.example.test', ['x-session-tenant' => 'acme'])['tenant'])->toBe('acme')
            ->and(status($app, '/notes', 'globex.example.test', ['x-session-tenant' => 'acme']))->toBe(404)
            ->and(status($app, '/notes', 'initech.io', ['x-session-tenant' => 'acme']))->toBe(404);
    });

    it('is refused for an action on another tenant\'s host', function () {
        $database = new Database();
        $app = app($database, [SignIn::class, SessionTenant::class]);

        $response = $app->post('/_action/NoteActions.touch', 'globex.example.test', ['note' => $database->notes['globex'][0]], ['x-session-tenant' => 'acme']);

        expect($response->status)->toBe(404);
    });
});

describe('a token minted on one tenant\'s host', function () {
    it('re-renders nothing on another tenant\'s host', function () {
        $database = new Database();
        $app = app($database);
        $acme = (string) $app->get('/notes', 'acme.example.test')->header('x-route-token');

        $mine = $app->post('/_action/NoteActions.touch', 'acme.example.test', ['note' => $database->notes['acme'][0], '_routes' => [$acme]]);
        $replayed = $app->post('/_action/NoteActions.touch', 'globex.example.test', ['note' => $database->notes['globex'][0], '_routes' => [$acme]]);

        expect(App::json($mine)['payloads'] ?? null)->not->toBe([])
            ->and($replayed->status)->toBe(200)
            ->and(App::json($replayed)['payloads'] ?? null)->toBe([])
            ->and($app->rerendered)->toBe([['route' => 'tenant.notes', 'tenant' => 'acme']]);
    });

    it('names its tenant and its host, and the custom domain\'s are its own', function () {
        $app = app();
        $acme = RouteToken::decode((string) $app->get('/notes', 'acme.example.test')->header('x-route-token'), $app->routeSigner)->claims;
        $initech = RouteToken::decode((string) $app->get('/notes', 'initech.io')->header('x-route-token'), $app->routeSigner)->claims;

        expect([$acme->tenant, $acme->host])->toBe(['acme', 'acme.example.test'])
            ->and([$initech->tenant, $initech->host])->toBe(['initech', 'initech.io']);
    });
});

describe('a worker answering one tenant after another', function () {
    it('leaves no tenant, identity map row or signed-in user from one request in the next', function () {
        $database = new Database();
        $app = app($database);

        $first = page($app, '/notes/' . $database->notes['acme'][0], 'acme.example.test');
        $tenantAfter = $app->orm->tenant();
        $userAfter = $app->auth->user();
        $foreign = status($app, '/notes/' . $database->notes['acme'][0], 'globex.example.test');
        $second = page($app, '/notes', 'globex.example.test');

        expect($first)->toBe(['body' => 'acme-note-1'])
            ->and($tenantAfter)->toBe('')
            ->and($userAfter)->toBeNull()
            ->and($foreign)->toBe(404)
            ->and($second['bodies'])->toBe(['globex-note-1', 'globex-note-2'])
            ->and($app->orm->tenant())->toBe('');
    });

    it('answers interleaved tenants each with its own rows', function () {
        $app = app();
        $seen = [];
        foreach (['acme.example.test', 'initech.io', 'globex.example.test', 'acme.example.test', 'initech.io'] as $host) {
            $seen[] = page($app, '/notes', $host)['bodies'][0];
        }

        expect($seen)->toBe(['acme-note-1', 'initech-note-1', 'globex-note-1', 'acme-note-1', 'initech-note-1']);
    });
});

describe('a worker\'s query cache', function () {
    it('keeps each tenant\'s cached answer to itself, and lets a tenant invalidate only its own', function () {
        CachedNoteBodies::$runs = [];
        $database = new Database();
        $app = app($database);
        $read = static fn (string $host): mixed => App::json($app->post('/_query/notes.cached', $host, ['args' => new \stdClass()]))['value'] ?? null;

        $acme = $read('acme.example.test');
        $globex = $read('globex.example.test');
        $read('acme.example.test');
        $read('globex.example.test');
        $cachedRuns = CachedNoteBodies::$runs;

        $renamed = $app->post('/_action/RenameNotes.rename', 'globex.example.test', ['note' => $database->notes['globex'][0], 'body' => 'globex-renamed']);
        $acmeAfter = $read('acme.example.test');
        $globexAfter = $read('globex.example.test');

        expect($acme)->toBe(['acme-note-1', 'acme-note-2'])
            ->and($globex)->toBe(['globex-note-1', 'globex-note-2'])
            ->and($cachedRuns)->toBe(['acme' => 1, 'globex' => 1])
            ->and($renamed->status)->toBe(200, $renamed->body())
            ->and($acmeAfter)->toBe(['acme-note-1', 'acme-note-2'])
            ->and($globexAfter)->toBe(['globex-note-2', 'globex-renamed'])
            ->and(CachedNoteBodies::$runs)->toBe(['acme' => 1, 'globex' => 2]);
    });
});

describe('a worker on Postgres, with row-level security', function () {
    it('tells each request its tenant, reads around the ORM only that tenant\'s rows, and leaves none told', function () {
        Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema): void {
            $pdo->exec('CREATE TABLE notes (id TEXT PRIMARY KEY, body TEXT NOT NULL, tenant_id TEXT NOT NULL)');
            $connection = new PdoConnection($pdo, 'pgsql');
            new Database($connection);
            foreach (RowLevelSecurity::forModel(Note::class) as $sql) {
                $pdo->exec($sql);
            }
            // A superuser bypasses every policy; the application connects as a role that does not.
            $role = 'tinvar_rls_' . bin2hex(random_bytes(4));
            $pdo->exec("CREATE ROLE {$role} NOLOGIN");
            $pdo->exec("GRANT USAGE ON SCHEMA {$schema} TO {$role}");
            $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {$schema} TO {$role}");
            $pdo->exec("SET ROLE {$role}");

            try {
                $app = new App($connection);
                $seen = [];
                $told = [];
                foreach (['acme.example.test', 'globex.example.test', 'initech.io', 'acme.example.test'] as $host) {
                    $seen[] = page($app, '/raw', $host)['bodies'];
                    $told[] = (string) $pdo->query("SELECT current_setting('app.tenant', true)")->fetchColumn();
                }

                expect($seen)->toBe([
                    ['acme-note-1', 'acme-note-2'],
                    ['globex-note-1', 'globex-note-2'],
                    ['initech-note-1', 'initech-note-2'],
                    ['acme-note-1', 'acme-note-2'],
                ])->and($told)->toBe(['', '', '', '']);
            } finally {
                $pdo->exec('RESET ROLE');
                $pdo->exec("SELECT set_config('app.tenant', '', false), set_config('app.across_tenants', 'off', false), set_config('app.subtree', '', false)");
                $pdo->exec("DROP OWNED BY {$role}");
                $pdo->exec("DROP ROLE {$role}");
            }
        });
    })->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');
});

describe('a tenant switch, through the application\'s own action', function () {
    it('offers every tenant of the user\'s on the apex, and only the domain\'s on a custom domain', function () {
        $app = app();
        $choices = static fn (string $host): mixed => App::json($app->post('/_action/SwitchTenant.choices', $host))['value'] ?? null;

        expect($choices(ROOT))->toBe(['acme', 'globex', 'initech'])
            ->and($choices('initech.io'))->toBe(['initech'])
            ->and($choices('acme.example.test'))->toBe(['acme']);
    });

    it('moves the session into a new one for a tenant in the host\'s subtree, and refuses any other with one 403', function () {
        $app = app();
        $switch = static fn (string $host, string $tenant) => $app->post('/_action/SwitchTenant.to', $host, ['tenant' => $tenant]);

        $fromApex = $switch(ROOT, 'globex');
        $offDomain = $switch('initech.io', 'acme');
        $offSubdomain = $switch('acme.example.test', 'globex');
        $nowhere = $switch(ROOT, 'umbrella');

        expect($fromApex->status)->toBe(200, $fromApex->body())
            ->and(App::json($fromApex)['value'])->toBe('session-after-globex')
            ->and([$offDomain->status, App::json($offDomain)['error']['code'] ?? null])->toBe([403, 'tenant_switch_refused'])
            ->and([$offSubdomain->status, App::json($offSubdomain)['error']['code'] ?? null])->toBe([403, 'tenant_switch_refused'])
            ->and([$nowhere->status, App::json($nowhere)['error']['code'] ?? null])->toBe([403, 'tenant_switch_refused'])
            ->and($app->sessions->switched)->toBe(['globex']);
    });

    it('reads the realm from the host the proxy proved, not a Host header behind it', function () {
        $app = app();

        $response = $app->kernel->handle(
            new \Tinvar\Http\Request(
                'POST',
                '/_action/SwitchTenant.to',
                [
                    'host' => ROOT,
                    'x-forwarded-host' => 'initech.io',
                    TrustedProxies::SECRET_HEADER => PROXY_SECRET,
                    'origin' => 'https://initech.io',
                    'content-type' => 'application/json',
                    'accept' => 'application/json',
                    'x-xsrf-token' => 'csrf',
                ],
                [],
                [],
                ['__Host-XSRF-TOKEN' => 'csrf'],
                remoteAddress: PROXY,
            ),
            static fn (): \Tinvar\Http\Response => \Tinvar\Http\Response::make(500, 'not a page'),
            json_encode(['tenant' => 'acme'], JSON_THROW_ON_ERROR),
        );

        expect([$response->status, App::json($response)['error']['code'] ?? null])->toBe([403, 'tenant_switch_refused'])
            ->and($app->sessions->switched)->toBe([]);
    });
});
