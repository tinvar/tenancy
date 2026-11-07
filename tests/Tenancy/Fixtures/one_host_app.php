<?php

declare(strict_types=1);

namespace Tinvar\Tests\Tenancy\Fixtures\OneHost;

use Tinvar\Accounts\AccountsKit;
use Tinvar\Accounts\StartSession;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Membership;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Auth\TenantMemberships;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Uuid;
use Tinvar\Tenancy\AccountTenantSessions;
use Tinvar\Tenancy\Host;
use Tinvar\Tenancy\Tenancy;
use Tinvar\Tenancy\TenantHosts;
use Tinvar\Tenancy\TenantSwitcher;
use Tinvar\Tests\Accounts\Fixtures\Member;
use Tinvar\Tests\Accounts\Fixtures\MemberApp;
use Tinvar\Tests\Accounts\Fixtures\Members;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/framework') . '/tests/Accounts/Fixtures/Members.php';

/**
 * An application that serves every tenant on one host, app.test, with an
 * organization switcher, signing in through the accounts kit (ADR 0181):
 * the session holds the tenant its user chose, StartSession runs each
 * request as it, and the panel's membership check asks in it every time.
 *
 * acme.test and globex.test are there too, each its tenant's by the host,
 * for the entry point that reads its tenant from the host -- which a
 * session's tenant must match, never overrule.
 */
#[Table('notes'), TenantScoped]
final class Note extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

final readonly class NotesData extends Data
{
    /** @param list<string> $bodies */
    public function __construct(public string $tenant, public array $bodies) {}
}

/** @return list<string> */
function bodies(Orm $orm): array
{
    return array_map(static fn (Note $note): string => $note->body, $orm->all(Note::class, 'body'));
}

/** The organization's notes, in the customer panel: in the session's tenant, for a member of it. */
#[Page('/notes', view: 'notes', name: 'notes'), Panel('customer')]
final readonly class PanelNotes
{
    public function __construct(private Orm $orm) {}

    public function __invoke(): NotesData
    {
        return new NotesData($this->orm->tenant(), bodies($this->orm));
    }
}

/** A tenant's notes on its own host: the host's tenant, which the session's must be. */
#[Page('/host/notes', view: 'host/notes', name: 'host.notes'), TenantFrom(Host::class)]
final readonly class HostNotes
{
    public function __construct(private Orm $orm) {}

    public function __invoke(): NotesData
    {
        return new NotesData($this->orm->tenant(), bodies($this->orm));
    }
}

/** The switcher's action, as TenantSwitcher's docs have an application's. */
final readonly class Organizations
{
    public function __construct(private TenantSwitcher $switcher) {}

    #[Action, NoAuthorization(reason: 'TenantSwitcher checks the membership itself')]
    public function switchTo(string $tenant, Request $request): ActionResult
    {
        $this->switcher->switch($request, $tenant, 'customer');

        return ActionResult::redirect('/notes');
    }
}

/** acme.test and globex.test are their tenants'; app.test is every tenant's, and names none. */
final class Hosts implements TenantHosts
{
    public function tenantForHost(string $host): ?string
    {
        return ['acme.test' => 'acme', 'globex.test' => 'globex'][$host] ?? null;
    }
}

/** Who is a customer where, as the test sets it, changed between requests. */
final class Roster implements Memberships, TenantMemberships
{
    /** @var array<string, list<string>> user id => their tenants */
    public array $tenants = [];

    public function membership(Authenticatable $user, string $panel, string $tenant): ?Membership
    {
        return $panel === 'customer' && \in_array($tenant, $this->tenants[$user->authIdentifier()] ?? [], true) ? new Membership('customer') : null;
    }

    public function tenantsOf(Authenticatable $user, string $panel): array
    {
        return $panel === 'customer' ? $this->tenants[$user->authIdentifier()] ?? [] : [];
    }
}

/** The application, one Kernel answering request after request as a worker does. */
final class App
{
    public readonly MemberApp $accounts;
    public readonly Kernel $kernel;
    public readonly Roster $roster;
    public readonly Orm $orm;

    public function __construct(?PdoConnection $connection = null)
    {
        $connection ??= PdoConnection::sqlite(':memory:', 'one-host');
        $schema = new Schema($connection, Dialect::for($connection));
        Members::define($schema);
        AccountsKit::migrate($schema, Member::class);
        $schema->create('notes', static function (\Tinvar\Orm\Schema\Table $notes): void {
            $notes->uuid('id')->primary();
            $notes->string('body', 100);
            $notes->tenant();
        });
        foreach (['acme', 'globex', 'umbrella'] as $tenant) {
            foreach ([1, 2] as $i) {
                $connection->execute('INSERT INTO notes (id, body, tenant_id) VALUES (?, ?, ?)', [Uuid::v7(), "{$tenant}-note-{$i}", $tenant]);
            }
        }

        $this->accounts = new MemberApp($connection, tenancy: Tenancy::tree());
        $this->orm = $this->accounts->orm;
        $this->roster = new Roster();
        $kit = $this->accounts->kit;
        $auth = $this->accounts->auth;

        $container = $kit->register(new Container())
            ->instance(TenantHosts::class, new Hosts())
            ->instance(TenantSwitcher::class, new TenantSwitcher(
                $this->orm,
                $auth,
                $this->roster,
                new AccountTenantSessions($kit->sessions),
                $kit->sessionId(...),
            ));

        $routes = new RouteRegistry();
        $routes->registerAll([PanelNotes::class, HostNotes::class]);
        $actions = new ActionRegistry($container->resolver());
        $actions->registerAll([Organizations::class]);

        $this->kernel = new Kernel(
            orm: $this->orm,
            routes: $routes,
            queries: new QueryRegistry($container->resolver()),
            actions: $actions,
            auth: $auth,
            policies: new PolicyRegistry(),
            resolver: $container->resolver(),
            tenants: new Tenancy($container->resolver()),
            allowedQueries: static fn (Request $r): array => [],
            globalMiddleware: [StartSession::class],
            gate: new AllowAllGate(),
            panelRegistry: new PanelRegistry(new PanelDefinition('customer')),
            memberships: $this->roster,
            panels: new PanelContext(),
            container: $container,
        );
    }

    /** A new member, and the cookie of a browser signed in as them on $host. */
    public function member(string $email, string $host = 'app.test'): string
    {
        $user = new Members($this->orm)->create($email, $email, password_hash('a passphrase long enough', PASSWORD_BCRYPT, ['cost' => 4]));

        return $this->accounts->kit->sessions->signedInCookie($user, new Request('GET', '/', ['host' => $host]));
    }

    public function idOf(string $email): string
    {
        return (string) new Members($this->orm)->findByEmail($email)?->authIdentifier();
    }

    public function get(string $path, string $cookie, string $host = 'app.test'): Response
    {
        return $this->kernel->handle(
            new Request('GET', $path, ['host' => $host], cookies: [$this->accounts->kit->cookie->name => $cookie]),
            static fn (LoadedRoute $loaded, Request $passed): Response => Response::make(200, json_encode(
                $loaded->page === null ? [] : get_object_vars($loaded->page),
                JSON_THROW_ON_ERROR,
            )),
        );
    }

    /** Switches the session $cookie carries to $tenant through the application's action. */
    public function switchTo(string $tenant, string $cookie, string $host = 'app.test'): Response
    {
        return $this->kernel->handle(
            new Request(
                'POST',
                '/_action/Organizations.switchTo',
                ['host' => $host, 'origin' => 'https://' . $host, 'content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => 'csrf'],
                [],
                [],
                ['__Host-XSRF-TOKEN' => 'csrf', $this->accounts->kit->cookie->name => $cookie],
            ),
            static fn (): Response => Response::make(500, 'not a page'),
            json_encode(['tenant' => $tenant], JSON_THROW_ON_ERROR),
        );
    }

    /** The session cookie $response set, or null for none. */
    public function cookieOf(Response $response): ?string
    {
        $line = $response->cookies()[$this->accounts->kit->cookie->name] ?? null;

        return $line !== null && preg_match('/^[^=]+=([^;]*);/', $line, $m) === 1 ? $m[1] : null;
    }

    /** @return array<string, mixed> */
    public static function json(Response $response): array
    {
        $decoded = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}
