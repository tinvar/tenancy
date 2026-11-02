<?php

declare(strict_types=1);

namespace Tinvar\Tests\Tenancy\Browser;

use Tinvar\Accounts\AccountUser;
use Tinvar\Accounts\AccountUsers;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Policy;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Auth\TenantMemberships;
use Tinvar\Auth\UserLookup;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Container\Container;
use Tinvar\Data\Encoder;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\HistoryKeys;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\CanonicalOrigins;
use Tinvar\Identity\DatabaseEmailLinkStore;
use Tinvar\Identity\DnsResolver;
use Tinvar\Identity\DnsTextResolver;
use Tinvar\Identity\EmailLinks;
use Tinvar\Identity\Invitations;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Observability\RequestId;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Protocol\Frame;
use Tinvar\Protocol\HeadTag;
use Tinvar\Protocol\PageMetadata;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\RenderChannel;
use Tinvar\Render\RouteToken;
use Tinvar\Routing\Domains;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tenancy\Host;
use Tinvar\Tenancy\SelfService\CustomDomainsConfig;
use Tinvar\Tenancy\SelfService\DatabaseTenantMembers;
use Tinvar\Tenancy\SelfService\DatabaseTenants;
use Tinvar\Tenancy\SelfService\Http\AcceptInvitationPage;
use Tinvar\Tenancy\SelfService\Http\CustomDomainsPage;
use Tinvar\Tenancy\SelfService\Http\TenantSettingsPage;
use Tinvar\Tenancy\SelfService\Http\TenantSwitchPage;
use Tinvar\Tenancy\SelfService\SubdomainAddresses;
use Tinvar\Tenancy\SelfService\TenantAdministration;
use Tinvar\Tenancy\SelfService\TenantAdministrationPolicy;
use Tinvar\Tenancy\SelfService\TenantDomains;
use Tinvar\Tenancy\SelfService\TenantInvites;
use Tinvar\Tenancy\SelfService\TenantLinks;
use Tinvar\Tenancy\SelfService\TenantSelfService;
use Tinvar\Tenancy\Subdomains;
use Tinvar\Tenancy\Tenancy;
use Tinvar\Tenancy\TenantHosts;

/**
 * A small multi-tenant application serving the tenant self-service pages
 * (ADR 0159) through the real renderer, for the browser test
 * (SelfServiceBrowserTest): tenants on `{slug}.localhost:PORT`, the
 * platform on `localhost:PORT`, everything kept in a SQLite file, built
 * afresh for each request -- php -S runs every request in a process of its
 * own.
 *
 * Fixture only: who is signed in is a cookie the front controller's
 * `/_e2e/sign-in` sets, DNS is a JSON file the test writes, and invites are
 * made at once, their links appended to a file.
 *
 * The environment: TENANCY_E2E_DATABASE (the SQLite file),
 * TENANCY_E2E_PORT, TENANCY_E2E_DNS (the DNS answers' file),
 * TENANCY_E2E_INVITES (the invites' file), TINVAR_SOCKET (the renderer).
 */
const KEY = 'tenancy-browser-test-application-key-0123456789abcdef';

const ROOT = 'localhost';

/** The cookie naming who is signed in. */
const USER_COOKIE = 'e2e_user';

/** The cookie naming the browser's session, which CSRF tokens and history keys are bound to. */
const SESSION_COOKIE = 'e2e_session';

#[Page('/organizations', view: 'tenancy/switch', name: 'tenancy.switch'), Domain('{tenant}.{root}'), TenantFrom(Host::class), Panel('customer')]
final class SwitchOrganization extends TenantSwitchPage {}

#[Page('/settings/domains', view: 'tenancy/domains', name: 'tenancy.domains'), Domain('{tenant}.{root}'), TenantFrom(Host::class), Panel('customer')]
final class OrganizationDomains extends CustomDomainsPage {}

#[Page('/settings', view: 'tenancy/settings', name: 'tenancy.settings'), Domain('{tenant}.{root}'), TenantFrom(Host::class), Panel('customer')]
final class OrganizationSettings extends TenantSettingsPage {}

#[Page('/invite', view: 'tenancy/invitation', name: 'tenancy.invitation'), Domain('{tenant}.{root}'), TenantFrom(Host::class)]
final class OrganizationInvitation extends AcceptInvitationPage
{
    /** The fixture has no home page: an accepted invite goes on to the settings. */
    protected function landing(): string
    {
        return '/settings';
    }
}

#[Policy(TenantAdministration::class)]
final class AdministrationRules extends TenantAdministrationPolicy {}

final readonly class Person implements AccountUser
{
    public function __construct(private string $id, private string $email, private string $name) {}

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
        return $this->name;
    }

    public function accountPasswordHash(): string
    {
        return '';
    }
}

/** The accounts: Ann (m-1), Bob (m-2), Cy (m-3), Dee (m-4) and Eve (m-9). */
final class People implements AccountUsers, UserLookup
{
    /** @var array<string, Person> */
    public array $people;

    public function __construct()
    {
        $this->people = [
            'm-1' => new Person('m-1', 'ann@example.net', 'Ann'),
            'm-2' => new Person('m-2', 'bob@example.net', 'Bob'),
            'm-3' => new Person('m-3', 'cy@example.net', 'Cy'),
            'm-4' => new Person('m-4', 'dee@example.net', 'Dee'),
            'm-9' => new Person('m-9', 'eve@example.net', 'Eve'),
        ];
    }

    public function find(string $id): ?AccountUser
    {
        return $this->people[$id] ?? null;
    }

    public function findByEmail(string $email): ?AccountUser
    {
        foreach ($this->people as $person) {
            if ($person->accountEmail() === strtolower(trim($email))) {
                return $person;
            }
        }

        return null;
    }

    public function create(string $email, string $name, string $passwordHash): AccountUser
    {
        throw new \LogicException('No sign-up in the fixture.');
    }

    public function changePassword(AccountUser $user, string $passwordHash): void {}

    public function changeEmail(AccountUser $user, string $email): void {}

    public function passwordHashes(int $limit): array
    {
        return [];
    }

    public function epoch(Authenticatable $user): string
    {
        return '1';
    }
}

/** Global: signs in whoever the fixture's cookie names, as a session would. */
final readonly class SignInFromCookie implements Middleware
{
    public function __construct(private AuthContext $auth, private People $people) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $this->people->find($request->cookie(USER_COOKIE) ?? '');
        if ($user !== null) {
            $this->auth->login($user, '1');
        }

        return $next($request);
    }
}

/**
 * DNS as the test's file says it is: `{"aliases": {name: target},
 * "texts": {name: [value, ...]}}`, read on every lookup.
 */
final readonly class FileDns implements DnsResolver, DnsTextResolver
{
    public function __construct(private string $file) {}

    public function alias(string $name, float $timeout): ?string
    {
        $alias = $this->answers()['aliases'][$name] ?? null;

        return \is_string($alias) ? $alias : null;
    }

    public function addresses(string $name, float $timeout): array
    {
        return [];
    }

    public function texts(string $name, float $timeout): array
    {
        $texts = $this->answers()['texts'][$name] ?? [];

        return \is_array($texts) ? array_values(array_filter($texts, \is_string(...))) : [];
    }

    /** @return array<string, mixed> */
    private function answers(): array
    {
        $json = is_file($this->file) ? (string) file_get_contents($this->file) : '';
        $answers = $json === '' ? [] : json_decode($json, true);

        return \is_array($answers) ? $answers : [];
    }
}

/** Invites made at once, as the queued job makes them on the worker, each link appended to a file as a JSON line. */
final class FileInvites implements TenantInvites
{
    public ?Invitations $invitations = null;

    public ?TenantLinks $links = null;

    public function __construct(private readonly string $file) {}

    public function send(Authenticatable $inviter, string $email, string $tenant, string $panel, string $role): void
    {
        $made = $this->invitations?->invite($inviter, $email, $tenant, $panel, $role, $this->links?->originFor($tenant) ?? '');
        file_put_contents(
            $this->file,
            json_encode(['email' => $email, 'tenant' => $tenant, 'role' => $role, 'url' => $made['url'] ?? ''], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }
}

/** The application, built for one request. */
final class SelfServiceApp
{
    private readonly Kernel $http;
    private readonly Orm $orm;
    private readonly RenderChannel $channel;
    private readonly Encoder $encoder;

    public function __construct(
        PdoConnection $connection,
        private readonly int $port,
        string $socket,
        string $dnsFile,
        string $invitesFile,
    ) {
        $origin = static fn (string $host): string => "http://{$host}:{$port}";
        $report = static function (\Throwable $e, ?string $digest): string {
            $digest ??= bin2hex(random_bytes(6));
            error_log("[tenancy-e2e] {$digest} " . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());

            return $digest;
        };
        $this->encoder = new Encoder($report);
        $this->orm = new Orm($connection, new IdentityMap('tenancy-e2e'), Tenancy::tree());
        $auth = new AuthContext();
        $people = new People();
        $tenants = new DatabaseTenants($connection);
        $members = new DatabaseTenantMembers($connection, $people);
        $domains = new TenantDomains($connection);
        $invites = new FileInvites($invitesFile);

        $realms = new Realms(
            Realm::platform($origin(ROOT)),
            [],
            static function (string $host) use ($tenants, $origin): ?Realm {
                if (!str_ends_with($host, '.' . ROOT)) {
                    return null;
                }
                $tenant = $tenants->tenantForSlug(substr($host, 0, -\strlen('.' . ROOT)));

                return $tenant === null ? null : Realm::platform($origin($host), $tenant);
            },
        );

        $kit = new TenantSelfService(
            orm: $this->orm,
            auth: $auth,
            memberships: $members,
            realms: $realms,
            profiles: $tenants,
            members: $members,
            emailLinks: EmailLinks::fromKey(KEY, new DatabaseEmailLinkStore($connection), $people, localHttp: true),
            users: $people,
            // Never reached: every tenant has an address of its own.
            canonical: new CanonicalOrigins('https://' . ROOT),
            addresses: new SubdomainAddresses(ROOT, $tenants->slugOf(...), scheme: 'http', port: $port, customDomain: $domains->primaryFor(...)),
            invites: $invites,
            domains: $domains,
            throttles: new ArrayThrottleStore(),
            domainsConfig: new CustomDomainsConfig(targets: ['domains.example.test'], platform: [ROOT], grace: 3600, limit: 3),
            dns: new FileDns($dnsFile),
            localHttp: true,
        );
        $invites->invitations = $kit->invitations;
        $invites->links = $kit->links;

        $container = new Container()
            ->instance(Orm::class, $this->orm)
            ->instance(AuthContext::class, $auth)
            ->instance(People::class, $people)
            ->instance(Memberships::class, $members)
            ->instance(TenantMemberships::class, $members)
            ->instance(AccountUsers::class, $people)
            ->instance(UserLookup::class, $people)
            ->instance(TenantHosts::class, new Subdomains([ROOT], $tenants->tenantForSlug(...)));
        $kit->register($container);

        $policies = new PolicyRegistry($container->resolver());
        $policies->register(TenantAdministration::class, AdministrationRules::class);
        $gate = new PolicyGate($policies, $auth);

        $pages = [SwitchOrganization::class, OrganizationDomains::class, OrganizationSettings::class, OrganizationInvitation::class];
        $routes = new RouteRegistry();
        $routes->registerAll($pages);
        $actions = new ActionRegistry($container->resolver());
        $actions->registerAll($pages);
        $queries = new QueryRegistry($container->resolver());

        $this->channel = new RenderChannel(
            $socket,
            new QueryRunner($queries, $this->orm, $gate, $this->encoder, report: $report),
            new MessagePackCodec(),
            report: $report,
        );

        $sessionId = static fn (Request $r): string => preg_match('/^[a-f0-9]{32}\z/', $r->cookie(SESSION_COOKIE) ?? '') === 1 ? (string) $r->cookie(SESSION_COOKIE) : '';
        $panels = new PanelContext();
        $builds = new BuildRegistry('tenancy-e2e-1');

        $this->http = new Kernel(
            orm: $this->orm,
            routes: $routes,
            queries: $queries,
            actions: $actions,
            auth: $auth,
            policies: $policies,
            resolver: $container->resolver(),
            allowedQueries: static fn (Request $r): array => [],
            globalMiddleware: [SignInFromCookie::class],
            gate: $gate,
            log: static function (\Throwable $e, string $digest) use ($report): void {
                $report($e, $digest);
            },
            fragments: new FragmentSupport(
                signer: new Signer(KEY, FragmentToken::PURPOSE),
                builds: $builds,
                render: static fn (): Response => Response::make(404, 'No deferred components in the fixture.'),
                sessionId: $sessionId,
                panels: $panels,
                routeSigner: new Signer(KEY, RouteToken::PURPOSE),
                orm: $this->orm,
                auth: $auth,
            ),
            panels: $panels,
            builds: $builds,
            container: $container,
            csrf: new CsrfTokens(new Signer(KEY, CsrfTokens::PURPOSE), $sessionId),
            historyKeys: new HistoryKeys(new Signer(KEY, HistoryKeys::PURPOSE), $sessionId),
            panelRegistry: new PanelRegistry(new PanelDefinition('customer')),
            memberships: $members,
            tenants: new Tenancy($container->resolver()),
            domains: new Domains(['root' => ROOT], scheme: 'http', port: $port, redirectable: ['{tenant}.{root}']),
        );
    }

    /** Built from the environment, as the front controller builds it. */
    public static function fromEnvironment(): self
    {
        return new self(
            PdoConnection::sqlite(self::env('TENANCY_E2E_DATABASE'), 'tenancy-e2e'),
            (int) self::env('TENANCY_E2E_PORT'),
            self::env('TINVAR_SOCKET'),
            self::env('TENANCY_E2E_DNS'),
            self::env('TENANCY_E2E_INVITES'),
        );
    }

    /**
     * The tables and the people in them: acme (Ann the owner, Bob an
     * admin, Cy a member), globex (Eve the owner, Ann a member) and initech
     * (Dee the owner).
     */
    public static function seed(string $database): void
    {
        if (is_file($database)) {
            unlink($database);
        }
        $connection = PdoConnection::sqlite($database, 'tenancy-e2e-seed', create: true);
        $schema = new Schema($connection, Dialect::for($connection));
        DatabaseTenants::define($schema);
        $connection->execute('CREATE TABLE tenant_members (tenant_id VARCHAR(191) NOT NULL, user_id VARCHAR(191) NOT NULL, panel VARCHAR(64) NOT NULL, role VARCHAR(64) NOT NULL, created_at VARCHAR(40) NOT NULL, PRIMARY KEY (tenant_id, panel, user_id))');
        TenantDomains::define($schema);
        DatabaseEmailLinkStore::define($schema);

        $tenants = new DatabaseTenants($connection);
        foreach (['acme' => 'Acme', 'globex' => 'Globex', 'initech' => 'Initech'] as $id => $name) {
            $tenants->create($name, $id, id: $id);
        }
        $members = new DatabaseTenantMembers($connection, new People());
        foreach ([['acme', 'm-1', 'owner'], ['acme', 'm-2', 'admin'], ['acme', 'm-3', 'member'], ['globex', 'm-9', 'owner'], ['globex', 'm-1', 'member'], ['initech', 'm-4', 'owner']] as [$tenant, $user, $role]) {
            $members->grant($tenant, 'customer', $user, $role);
        }
    }

    public function handle(Request $request, string $rawBody): Response
    {
        return $this->http->handle($request, $this->respond(...), $rawBody);
    }

    /** Renders a matched route: the document, or for the client router the payload alone. */
    private function respond(LoadedRoute $loaded, Request $request): Response
    {
        $payloadOnly = $request->wantsRscPayload();
        $body = '';
        $sink = static function (string $bytes) use (&$body): void {
            $body .= $bytes;
        };
        $nonce = base64_encode(random_bytes(16));
        $page = $loaded->chain->page;

        $this->orm->beginRender();
        try {
            $loaderData = $this->orm->readOnly(fn (): array => $loaded->encode($this->encoder, $request));
            $frame = Frame::render(
                routeId: $page->view,
                params: $loaded->chain->parameters,
                loaderData: $loaderData,
                requestId: RequestId::of($request),
                cspNonce: $nonce,
                allowedQueries: [],
                page: $payloadOnly ? null : new PageMetadata(
                    url: $request->rawQuery === null || $request->rawQuery === '' ? $request->path : "{$request->path}?{$request->rawQuery}",
                    version: 'tenancy-e2e-1',
                    routeToken: self::stringOrNull($request->attribute(Kernel::ROUTE_TOKEN)),
                    view: $page->view,
                    historyKey: self::stringOrNull($request->attribute(Kernel::HISTORY_KEY)),
                    route: $page->name ?? $page->view,
                ),
                segments: $loaded->segments,
                csrfToken: self::stringOrNull($request->attribute(Kernel::CSRF_TOKEN)),
                head: [HeadTag::title('Tenancy')],
                hosts: $this->http->hostsFor($request, $loaded),
            );
            if ($payloadOnly) {
                $frame['rsc'] = true;
            }
            $this->channel->render($frame, [], $sink);
        } finally {
            $this->orm->endRender();
        }

        if ($payloadOnly) {
            return Response::make(200, $body, ['content-type' => 'text/x-component']);
        }

        return Response::make(200, $body, [
            'content-type' => 'text/html; charset=utf-8',
            'content-security-policy' => "script-src 'nonce-{$nonce}' 'strict-dynamic'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if (!\is_string($value) || $value === '') {
            throw new \RuntimeException("The tenancy browser fixture needs {$name}.");
        }

        return $value;
    }
}
