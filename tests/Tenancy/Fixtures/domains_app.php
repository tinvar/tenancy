<?php

declare(strict_types=1);

namespace Tinvar\Tests\Tenancy\Fixtures\Domains;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\TrustedProxies;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Connection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\RouteToken;
use Tinvar\Routing\Domains;
use Tinvar\Routing\HostMatch;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Support\Uuid;
use Tinvar\Tenancy\Host;
use Tinvar\Tenancy\Subdomains;
use Tinvar\Tenancy\Tenancy;
use Tinvar\Tenancy\TenantHosts;
use Tinvar\Tenancy\TenantSessions;
use Tinvar\Tenancy\TenantSwitcher;
use Tinvar\Auth\Membership;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\TenantMemberships;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;

/**
 * A multi-tenant application that routes by host (ADR 0149), what the shop
 * -- one store, no tenant -- does not exercise: tenants on
 * `{tenant}.example.test` and on a custom domain of their own, the
 * platform on the apex, `www.` answered as the apex, and staff on
 * `admin.example.test`, behind a pinned proxy that proves itself with a
 * secret.
 *
 * - acme and globex are subdomains (`acme.example.test`), initech is
 *   `initech.io`, a custom domain served as `{tenant}.{root}`.
 * - umbrella has a slug and is suspended: the application's lookup answers
 *   no tenant for it, as for one deleted.
 * - Two tenants signed up with the slugs `admin` and `www`, which the
 *   platform's own hosts also are.
 */
const KEY = 'domains-tenancy-test-application-key-0123456789';

const SESSION = 'session-domains';

const ROOT = 'example.test';

const PROXY = '10.0.0.7';

const PROXY_SECRET = 'a-proxy-secret-long-enough-to-be-taken-0123456789';

#[Table('notes'), TenantScoped]
final class Note extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

/** Three tenants' notes, and one for each tenant whose slug is a platform host's. */
final class Database
{
    /** @var array<string, list<string>> tenant => note ids */
    public array $notes = [];

    public PdoConnection $connection;

    public function __construct(?PdoConnection $connection = null)
    {
        if ($connection === null) {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE notes (id TEXT PRIMARY KEY, body TEXT NOT NULL, tenant_id TEXT NOT NULL)');
            $connection = new PdoConnection($pdo, 'domains-test');
        }
        $this->connection = $connection;

        foreach (['acme', 'globex', 'initech', 'tenant-admin', 'tenant-www', 'umbrella'] as $tenant) {
            for ($i = 1; $i <= 2; $i++) {
                $id = Uuid::v7();
                $this->notes[$tenant][] = $id;
                $connection->execute('INSERT INTO notes (id, body, tenant_id) VALUES (?, ?, ?)', [$id, "{$tenant}-note-{$i}", $tenant]);
            }
        }
    }
}

/**
 * The application's map from a host to its tenant: a slug under the
 * platform's domain, or a verified custom domain. A suspended tenant's slug
 * and domain answer nothing, as a deleted one's would.
 */
final class Directory implements TenantHosts
{
    /** @var array<string, string> slug => tenant */
    public array $slugs = ['acme' => 'acme', 'globex' => 'globex', 'admin' => 'tenant-admin', 'www' => 'tenant-www', 'umbrella' => 'umbrella'];

    /** @var array<string, string> custom domain => tenant */
    public array $custom = ['initech.io' => 'initech'];

    /** @var list<string> */
    public array $suspended = ['umbrella'];

    /** @var (\Closure(string): ?string)|null the slug a tenant that left a label is at now */
    public ?\Closure $movedFrom = null;

    public function tenantForHost(string $host): ?string
    {
        $tenant = $this->custom[$host] ?? new Subdomains([ROOT], fn (string $label): ?string => $this->slugs[$label] ?? null, $this->movedFrom)->tenantForHost($host);

        return $tenant === null || \in_array($tenant, $this->suspended, true) ? null : $tenant;
    }

    /**
     * What Domains serves a custom domain as: `{tenant}.{root}` with the
     * tenant's slug, the label its subdomain would have.
     *
     * @return array<string, string>|null
     */
    public function customDomain(string $host): ?array
    {
        $tenant = $this->custom[$host] ?? null;
        if ($tenant === null || \in_array($tenant, $this->suspended, true)) {
            return null;
        }

        return ['tenant' => $tenant];
    }
}

final readonly class Member implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/** Global: signs a member in on every request, as a session would. */
final readonly class SignIn implements Middleware
{
    public function __construct(private AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $this->auth->login(new Member('m-1'), '1');

        return $next($request);
    }
}

/**
 * Global: runs the request as the tenant its session holds -- the
 * `x-session-tenant` header standing in for one read from the session.
 */
final readonly class SessionTenant implements Middleware
{
    public function __construct(private Orm $orm) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $tenant = $request->header('x-session-tenant');

        return $tenant === null ? $next($request) : $this->orm->actAs($tenant, static fn (): Response => $next($request));
    }
}

final readonly class NotesData extends Data
{
    /** @param list<string> $bodies */
    public function __construct(public string $tenant, public string $label, public string $host, public array $bodies) {}
}

final readonly class NoteData extends Data
{
    public function __construct(public string $body) {}
}

final readonly class TextData extends Data
{
    public function __construct(public string $text) {}
}

/** @return list<string> */
function bodies(Orm $orm): array
{
    return array_map(static fn (Note $note): string => $note->body, $orm->all(Note::class, 'body'));
}

/** A tenant's notes, on its own host: the host's label is a value, the tenant is the source's. */
#[Page('/notes', view: 'tenant/notes', name: 'tenant.notes'), Domain('{tenant}.{root}'), TenantFrom(Host::class)]
final readonly class TenantNotes
{
    public function __construct(private Orm $orm) {}

    public function __invoke(string $tenant, Request $request): NotesData
    {
        return new NotesData($this->orm->tenant(), $tenant, HostMatch::hostOf($request), bodies($this->orm));
    }
}

#[Page('/notes/{note}', view: 'tenant/note', name: 'tenant.note'), Domain('{tenant}.{root}'), TenantFrom(Host::class), Authorize('view', 'note')]
final class TenantNote
{
    public function __invoke(string $tenant, Note $note): NoteData
    {
        return new NoteData($note->body);
    }
}

/** Reads around the ORM, with no tenant predicate: only row-level security holds it. */
#[Page('/raw', view: 'tenant/raw', name: 'tenant.raw'), Domain('{tenant}.{root}'), TenantFrom(Host::class)]
final readonly class TenantRaw
{
    public function __construct(private Orm $orm) {}

    public function __invoke(string $tenant): NotesData
    {
        return new NotesData($this->orm->tenant(), $tenant, '', array_map(
            static fn (array $row): string => (string) $row['body'],
            $this->orm->connection()->select('SELECT body FROM notes ORDER BY body'),
        ));
    }
}

/** The platform's home, on the apex and on www. */
/** On a tenant's host, reading no tenant from it: it would run as the session's tenant. */
#[Page('/leaky', view: 'tenant/leaky', name: 'tenant.leaky'), Domain('{tenant}.{root}')]
final readonly class LeakyNotes
{
    public function __invoke(string $tenant): TextData
    {
        return new TextData($tenant);
    }
}

/** On a tenant's host, reading its tenant from a signed link rather than the host. */
#[Page('/signed', view: 'tenant/signed', name: 'tenant.signed'), Domain('{tenant}.{root}'), TenantFrom(\Tinvar\Tenancy\Signature::class)]
final readonly class SignedNotes
{
    public function __invoke(string $tenant): TextData
    {
        return new TextData($tenant);
    }
}

/** An action on a tenant's host, reading no tenant from it. */
#[Domain('{tenant}.{root}')]
final readonly class LeakyActions
{
    #[Action(name: 'notes.leak'), NoAuthorization(reason: 'a fixture refused before it runs')]
    public function leak(): \Tinvar\Actions\ActionResult
    {
        return \Tinvar\Actions\ActionResult::ok();
    }
}

/** An action in a panel living on a tenant's host, declaring no #[Domain] of its own and reading no tenant from it. */
#[\Tinvar\Attributes\Panel('customer')]
final readonly class PanelLeakyActions
{
    public function __construct(private Orm $orm) {}

    #[Action(name: 'panel.leak'), NoAuthorization(reason: 'a fixture refused before it runs')]
    public function leak(): ActionResult
    {
        return ActionResult::ok($this->orm->tenant());
    }
}

#[Page('/', view: 'home', name: 'home')]
final class Home
{
    public function __invoke(): TextData
    {
        return new TextData('platform');
    }
}

/** A platform page that, by mistake, takes its tenant from the host. */
#[Page('/notes', view: 'platform/notes', name: 'platform.notes'), TenantFrom(Host::class)]
final readonly class PlatformNotes
{
    public function __construct(private Orm $orm) {}

    public function __invoke(Request $request): NotesData
    {
        return new NotesData($this->orm->tenant(), '', HostMatch::hostOf($request), bodies($this->orm));
    }
}

/** Staff, on their own host, with no tenant. */
#[Page('/', view: 'admin/home', name: 'admin.home'), Domain('admin.{root}')]
final class AdminHome
{
    public function __invoke(): TextData
    {
        return new TextData('staff');
    }
}

final class NoteActions
{
    /** On any host the application serves, as its host's tenant. */
    #[Action, TenantFrom(Host::class), Authorize('update', 'note')]
    public function touch(Note $note): ActionResult
    {
        return ActionResult::ok($note->body)->withRoutes('tenant.notes');
    }
}

/** Each tenant's notes, cached under the `notes` tag, counting the times its body ran for each tenant. */
#[Query(name: 'notes.cached', cache: '5m', tags: static function (): array {
    return ['notes'];
}), TenantFrom(Host::class), NoAuthorization(reason: 'a fixture listing its tenant\'s notes')]
final class CachedNoteBodies
{
    /** @var array<string, int> tenant => body runs */
    public static array $runs = [];

    public function __construct(private readonly Orm $orm) {}

    /** @return list<string> */
    public function __invoke(): array
    {
        self::$runs[$this->orm->tenant()] = (self::$runs[$this->orm->tenant()] ?? 0) + 1;

        return bodies($this->orm);
    }
}

final readonly class RenameNotes
{
    public function __construct(private Orm $orm) {}

    /** Renames a note, and invalidates the `notes` tag -- its own tenant's. */
    #[Action, TenantFrom(Host::class), Authorize('update', 'note')]
    public function rename(Note $note, string $body): ActionResult
    {
        $note->body = $body;
        $this->orm->save($note);

        return ActionResult::ok($note->body)->withTags('notes');
    }
}

/** m-1 is a customer of acme, globex and initech -- and of nothing else. */
final class Customers implements Memberships, TenantMemberships
{
    public function membership(Authenticatable $user, string $panel, string $tenant): ?Membership
    {
        return $user->authIdentifier() === 'm-1' && $panel === 'customer' && \in_array($tenant, ['acme', 'globex', 'initech'], true)
            ? new Membership('customer')
            : null;
    }

    public function tenantsOf(Authenticatable $user, string $panel): array
    {
        return $user->authIdentifier() === 'm-1' && $panel === 'customer' ? ['acme', 'globex', 'initech'] : [];
    }
}

/** A switch's last step: a new session, as an application keeping sessions takes it. */
final class Sessions implements TenantSessions
{
    /** @var list<string> the tenant each switch moved to */
    public array $switched = [];

    public function switchTo(Request $request, Authenticatable $user, string $tenant): string
    {
        $this->switched[] = $tenant;

        return 'session-after-' . $tenant;
    }
}

/** The application's own switch page's action and query, as TenantSwitcher's docs have them. */
final readonly class SwitchTenant
{
    public function __construct(private TenantSwitcher $switcher) {}

    #[Action, NoAuthorization(reason: 'TenantSwitcher checks the membership itself')]
    public function to(string $tenant, Request $request): ActionResult
    {
        return ActionResult::ok($this->switcher->switch($request, $tenant, 'customer'));
    }

    #[Action, NoAuthorization(reason: 'TenantSwitcher checks the membership itself')]
    public function choices(Request $request): ActionResult
    {
        return ActionResult::ok($this->switcher->choices($request, 'customer'));
    }
}

/**
 * The application's hosts, as realms: the platform's apex, where every
 * tenant of a user's may be switched to; each subdomain, its tenant's; and
 * initech's custom domain, held to initech's subtree.
 */
function realms(): Realms
{
    return new Realms(
        Realm::platform('https://' . ROOT),
        [Realm::platform('https://admin.' . ROOT, panel: 'admin')],
        static fn (string $host): ?Realm => match ($host) {
            'acme.' . ROOT => Realm::platform('https://acme.' . ROOT, 'acme'),
            'globex.' . ROOT => Realm::platform('https://globex.' . ROOT, 'globex'),
            'initech.io' => Realm::domain('https://initech.io', 'initech', 'customer'),
            default => null,
        },
    );
}

#[Query(name: 'notes.bodies'), TenantFrom(Host::class), NoAuthorization(reason: 'a fixture listing its tenant\'s notes')]
final readonly class NoteBodies
{
    public function __construct(private Orm $orm) {}

    /** @return list<string> */
    public function __invoke(): array
    {
        return bodies($this->orm);
    }
}

/**
 * The application around them, kept for as long as a test likes: one
 * Kernel answering request after request, as a worker does.
 */
final class App
{
    public readonly Kernel $kernel;
    public readonly Orm $orm;
    public readonly AuthContext $auth;
    public readonly Signer $routeSigner;
    public readonly Directory $directory;
    public readonly Sessions $sessions;
    public readonly Container $container;

    /** @var list<array{route: string, tenant: string}> */
    public array $rerendered = [];

    /** @var list<string> the tenant each fragment rendered as */
    public array $fragments = [];

    /**
     * @param list<class-string<Middleware>> $global
     */
    /**
     * @param list<class-string> $routes pages and layouts beside the fixture's own
     * @param list<class-string> $actions action holders beside the fixture's own
     * @param array<class-string, object> $instances put in the container beside the fixture's own
     * @param list<class-string> $queries queries beside the fixture's own
     * @param list<string> $redirectable host patterns an action may send a browser to (Domains)
     */
    public function __construct(
        Connection $connection,
        array $global = [SignIn::class],
        ?string $proxySecret = PROXY_SECRET,
        array $routes = [],
        array $actions = [],
        ?\Tinvar\Auth\Gate $gate = null,
        ?PolicyRegistry $policies = null,
        array $instances = [],
        ?\Tinvar\Auth\PanelRegistry $panelRegistry = null,
        ?Memberships $memberships = null,
        array $queries = [],
        array $redirectable = [],
        ?Orm $orm = null,
        ?AuthContext $auth = null,
        ?Container $container = null,
    ) {
        $this->orm = $orm ?? new Orm($connection, new IdentityMap('domains-test'), Tenancy::tree());
        $this->auth = $auth ?? new AuthContext();
        $this->routeSigner = new Signer(KEY, RouteToken::PURPOSE);
        $this->directory = new Directory();
        $panels = new PanelContext();

        $this->sessions = new Sessions();
        $this->container = $container = ($container ?? new Container())
            ->instance(TenantHosts::class, $this->directory)
            ->instance(TenantSwitcher::class, new TenantSwitcher(
                $this->orm,
                $this->auth,
                new Customers(),
                $this->sessions,
                static fn (Request $r): string => SESSION,
                realms(),
            ));
        foreach ($instances as $class => $instance) {
            $container->instance($class, $instance);
        }

        $extraRoutes = $routes;
        $routes = new RouteRegistry();
        $routes->registerAll([TenantNotes::class, TenantNote::class, TenantRaw::class, Home::class, PlatformNotes::class, AdminHome::class, ...$extraRoutes]);

        $extraActions = $actions;
        $actions = new ActionRegistry($container->resolver());
        $actions->registerAll([NoteActions::class, RenameNotes::class, SwitchTenant::class, ...$extraActions]);

        $extraQueries = $queries;
        $queries = new QueryRegistry($container->resolver());
        $queries->registerAll([NoteBodies::class, CachedNoteBodies::class, ...$extraQueries]);

        $directory = $this->directory;
        $domains = new Domains(
            ['root' => ROOT],
            aliases: ['www.' . ROOT],
            proxies: TrustedProxies::of(['10.0.0.0/8'], [], $proxySecret),
            custom: static fn (string $host): ?array => $directory->customDomain($host),
            customAs: '{tenant}.{root}',
            redirectable: $redirectable,
        );

        $this->kernel = new Kernel(
            orm: $this->orm,
            routes: $routes,
            queries: $queries,
            actions: $actions,
            auth: $this->auth,
            policies: $policies ?? new PolicyRegistry(),
            resolver: $container->resolver(),
            tenants: new Tenancy($container->resolver()),
            allowedQueries: $extraQueries === [] ? static fn (Request $r): array => ['notes.bodies', 'notes.cached'] : static fn (Request $r): array => $queries->names(),
            globalMiddleware: $global,
            gate: $gate ?? new AllowAllGate(),
            panelRegistry: $panelRegistry,
            memberships: $memberships,
            fragments: new FragmentSupport(
                signer: new Signer(KEY, FragmentToken::PURPOSE),
                builds: new BuildRegistry('build-1'),
                render: function (): Response {
                    $this->fragments[] = $this->orm->tenant();

                    return Response::make(200, 'RSC-FRAGMENT');
                },
                sessionId: static fn (Request $r): string => SESSION,
                panels: $panels,
                routeSigner: $this->routeSigner,
                renderRoute: function (LoadedRoute $loaded): string {
                    $this->rerendered[] = ['route' => $loaded->chain->page->name ?? '', 'tenant' => $this->orm->tenant()];

                    return 'RSC-ROUTE';
                },
                orm: $this->orm,
                auth: $this->auth,
            ),
            panels: $panels,
            container: $container,
            builds: new BuildRegistry('build-1'),
            domains: $domains,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $path, string $host, array $headers = [], ?string $peer = null): Response
    {
        $rawQuery = parse_url($path, PHP_URL_QUERY);
        parse_str(\is_string($rawQuery) ? $rawQuery : '', $query);
        $path = (string) parse_url($path, PHP_URL_PATH);

        return $this->kernel->handle(
            new Request('GET', $path, ['host' => $host, ...$headers], array_filter($query, \is_string(...)), rawQuery: \is_string($rawQuery) ? $rawQuery : null, remoteAddress: $peer),
            static fn (LoadedRoute $loaded, Request $passed): Response => Response::make(200, json_encode([
                'page' => $loaded->page === null ? [] : get_object_vars($loaded->page),
            ], JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function post(string $path, string $host, array $body = [], array $headers = []): Response
    {
        return $this->kernel->handle(
            new Request(
                'POST',
                $path,
                ['host' => $host, 'origin' => 'https://' . $host, 'content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => 'csrf', ...$headers],
                [],
                [],
                ['__Host-XSRF-TOKEN' => 'csrf'],
            ),
            static fn (): Response => Response::make(500, 'not a page'),
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    public static function json(Response $response): array
    {
        $decoded = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}

/** A query on a tenant's host, reading no tenant from it. */
#[Query(name: 'notes.leaky'), Domain('{tenant}.{root}'), NoAuthorization(reason: 'a fixture refused before it runs')]
final readonly class LeakyNoteBodies
{
    public function __construct(private Orm $orm) {}

    /** @return list<string> */
    public function __invoke(): array
    {
        return bodies($this->orm);
    }
}

/** A channel on a tenant's host, reading no tenant from it. */
#[\Tinvar\Attributes\Channel('notes.leaky', message: TextData::class), Domain('{tenant}.{root}'), NoAuthorization(reason: 'a fixture refused before it runs')]
final readonly class LeakyNoteFeed {}
