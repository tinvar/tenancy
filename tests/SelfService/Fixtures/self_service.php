<?php

declare(strict_types=1);

namespace Tinvar\Tests\Tenancy\SelfService\Fixtures;

use Carbon\CarbonImmutable;
use Tinvar\Accounts\AccountUser;
use Tinvar\Accounts\AccountUsers;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Policy;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Auth\TenantMemberships;
use Tinvar\Auth\UserLookup;
use Tinvar\Container\Container;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\ArrayEmailLinkStore;
use Tinvar\Identity\CanonicalOrigins;
use Tinvar\Identity\DnsLookupFailed;
use Tinvar\Identity\DnsResolver;
use Tinvar\Identity\DnsTextResolver;
use Tinvar\Identity\EmailLinks;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Mail\Email;
use Tinvar\Mail\Mailable;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Tenancy\Host;
use Tinvar\Tenancy\SelfService\CustomDomainsConfig;
use Tinvar\Tenancy\SelfService\DatabaseTenantMembers;
use Tinvar\Tenancy\SelfService\DatabaseTenants;
use Tinvar\Tenancy\SelfService\Http\AcceptInvitationPage;
use Tinvar\Tenancy\SelfService\Http\CustomDomainsPage;
use Tinvar\Tenancy\SelfService\Http\CustomDomainStatuses;
use Tinvar\Tenancy\SelfService\Http\TenantSettingsPage;
use Tinvar\Tenancy\SelfService\Http\TenantSwitchPage;
use Tinvar\Tenancy\SelfService\SubdomainAddresses;
use Tinvar\Tenancy\SelfService\TenantActivity;
use Tinvar\Tenancy\SelfService\TenantAdministration;
use Tinvar\Tenancy\SelfService\TenantAdministrationPolicy;
use Tinvar\Tenancy\SelfService\TenantChange;
use Tinvar\Tenancy\SelfService\TenantDomains;
use Tinvar\Tenancy\SelfService\TenantInviteMails;
use Tinvar\Tenancy\SelfService\TenantInvites;
use Tinvar\Tenancy\SelfService\TenantSelfService;
use Tinvar\Tenancy\SelfService\TenantSwitchboard;
use Tinvar\Tenancy\Tenancy;
use Tinvar\Tenancy\TenantSwitcher;
use Tinvar\Tests\Tenancy\Fixtures\Domains\App;
use Tinvar\Tests\Tenancy\Fixtures\Domains\Customers;
use Tinvar\Tests\Tenancy\Fixtures\Domains\Database;
use Tinvar\Tests\Tenancy\Fixtures\Domains\Sessions;
use Tinvar\Tests\Tenancy\Fixtures\Domains\SignIn;
use Tinvar\Identity\Invitation;
use Tinvar\Data\Data;

use function Tinvar\Tests\Tenancy\Fixtures\Domains\realms;

use const Tinvar\Tests\Tenancy\Fixtures\Domains\KEY;
use const Tinvar\Tests\Tenancy\Fixtures\Domains\ROOT;
use const Tinvar\Tests\Tenancy\Fixtures\Domains\SESSION;

require_once \dirname(__DIR__, 2) . '/Tenancy/Fixtures/domains_app.php';

/**
 * The tenant self-service pages (ADR 0161), declared as an application
 * declares them, in the multi-tenant application of domains_app.php:
 * tenants on `{tenant}.example.test`, initech on its custom domain
 * `initech.io`, the platform on the apex.
 */
#[Page('/organizations', view: 'tenancy/switch', name: 'tenancy.switch'), Domain('{tenant}.{root}'), TenantFrom(Host::class), Panel('customer')]
final class SwitchOrganization extends TenantSwitchPage {}

/** On the platform's apex, in no tenant and so in no panel's membership: the panel is named. */
#[Page('/organizations', view: 'tenancy/switch', name: 'platform.switch')]
final class PlatformSwitch extends TenantSwitchPage
{
    protected function panel(): string
    {
        return 'customer';
    }

    protected function landing(string $tenant): string
    {
        return '/notes';
    }
}

#[Page('/settings/domains', view: 'tenancy/domains', name: 'tenancy.domains'), Domain('{tenant}.{root}'), TenantFrom(Host::class), Panel('customer')]
final class OrganizationDomains extends CustomDomainsPage {}

#[Query(name: 'tenancy.domains'), TenantFrom(Host::class), Panel('customer')]
final class OrganizationDomainStatuses extends CustomDomainStatuses {}

#[Page('/settings', view: 'tenancy/settings', name: 'tenancy.settings'), Domain('{tenant}.{root}'), TenantFrom(Host::class), Panel('customer')]
final class OrganizationSettings extends TenantSettingsPage {}

#[Page('/invite', view: 'tenancy/invitation', name: 'tenancy.invitation'), Domain('{tenant}.{root}'), TenantFrom(Host::class)]
final class OrganizationInvitation extends AcceptInvitationPage {}

#[Policy(TenantAdministration::class)]
final class AdministrationRules extends TenantAdministrationPolicy {}

/** Every change a page recorded, in order. */
final class RecordedActivity implements TenantActivity
{
    /** @var list<array{change: string, tenant: string, actor: string|null, details: array<string, string>}> */
    public array $records = [];

    public function record(TenantChange $change, string $tenant, ?Authenticatable $actor, array $details = []): void
    {
        $this->records[] = ['change' => $change->value, 'tenant' => $tenant, 'actor' => $actor?->authIdentifier(), 'details' => $details];
    }

    /** @return list<string> */
    public function changes(): array
    {
        return array_column($this->records, 'change');
    }
}

/** DNS as the test says it is: aliases, addresses and TXT records by name, and names whose lookups fail. */
final class FakeDns implements DnsResolver, DnsTextResolver
{
    /** @var array<string, string> */
    public array $aliases = [];

    /** @var array<string, list<string>> */
    public array $addresses = [];

    /** @var array<string, list<string>> */
    public array $texts = [];

    /** @var array<string, true> */
    public array $failing = [];

    /** @var array<string, int> names that answer this many more lookups, then fail as $failing's do */
    public array $failAfter = [];

    /** @var list<string> every name asked about */
    public array $asked = [];

    public function alias(string $name, float $timeout): ?string
    {
        $this->ask($name);

        return $this->aliases[$name] ?? null;
    }

    public function addresses(string $name, float $timeout): array
    {
        $this->ask($name);

        return $this->addresses[$name] ?? [];
    }

    public function texts(string $name, float $timeout): array
    {
        $this->ask($name);

        return $this->texts[$name] ?? [];
    }

    /** Points $host at the platform, and publishes $token as its ownership record. */
    public function prove(string $host, ?string $token): void
    {
        $this->aliases[$host] = 'domains.example.test';
        if ($token !== null) {
            $this->texts['_tinvar-challenge.' . $host] = ['tinvar-domain=' . $token];
        }
    }

    private function ask(string $name): void
    {
        $this->asked[] = $name;
        if (isset($this->failAfter[$name]) && $this->failAfter[$name]-- <= 0) {
            $this->failing[$name] = true;
        }
        if (isset($this->failing[$name])) {
            throw new DnsLookupFailed("The lookup of {$name} failed.");
        }
    }
}

/** An account, as the accounts kit's users are. */
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

/** The application's accounts: Ann (m-1), Bob (m-2), Cy (m-3), Dee (m-4) and Eve (m-9, acme's attacker at globex). */
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
            if ($person->accountEmail() === $email) {
                return $person;
            }
        }

        return null;
    }

    public function create(string $email, string $name, string $passwordHash): AccountUser
    {
        throw new \LogicException('Not in these tests.');
    }

    public function changePassword(AccountUser $user, string $passwordHash): void {}

    public function changePasswordFrom(AccountUser $user, string $current, string $passwordHash): bool
    {
        return false;
    }

    public function storedPasswordHash(AccountUser $user): ?string
    {
        return $this->find($user->authIdentifier())?->accountPasswordHash();
    }

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

/** Global: signs in whoever `x-user` names -- m-1 unless it names someone -- as a session would. */
final readonly class SignInAs implements Middleware
{
    public function __construct(private AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->header('x-user') ?? 'm-1';
        if ($user !== 'nobody') {
            $this->auth->login(new Person($user, '', ''), '1', $request->header('x-api-token'), $request->header('x-impersonator'));
        }

        return $next($request);
    }
}

/** Invites made at once, as the queued job makes them on the worker, the URLs kept for the test to open. */
final class SentInvites implements TenantInvites
{
    /** @var list<array{email: string, tenant: string, role: string, url: string}> */
    public array $sent = [];

    public ?\Tinvar\Identity\Invitations $invitations = null;

    public ?\Tinvar\Tenancy\SelfService\TenantLinks $links = null;

    public function send(Authenticatable $inviter, string $email, string $tenant, string $panel, string $role): void
    {
        $made = $this->invitations?->invite($inviter, $email, $tenant, $panel, $role, $this->links?->originFor($tenant) ?? '');
        $this->sent[] = ['email' => $email, 'tenant' => $tenant, 'role' => $role, 'url' => $made['url'] ?? ''];
    }

    /** The token the last invite's URL carries. */
    public function lastToken(): string
    {
        parse_str((string) parse_url(end($this->sent)['url'] ?? '', PHP_URL_QUERY), $query);

        return \is_string($query['token'] ?? null) ? $query['token'] : '';
    }
}

final readonly class InviteMailData extends Data
{
    public function __construct(public string $url, public string $organization, public string $inviter) {}
}

/** The application's invite email: a template, and its props. */
final class InviteMails implements TenantInviteMails
{
    public function invite(Invitation $invitation, string $url, string $organization, string $inviter): Mailable
    {
        return new readonly class ($invitation->email, $url, $organization, $inviter) implements Mailable {
            public function __construct(private string $to, private string $url, private string $organization, private string $inviter) {}

            public function email(): Email
            {
                return new Email(new \Tinvar\Mail\Address($this->to), "Join {$this->organization}", 'tenant-invite', new InviteMailData($this->url, $this->organization, $this->inviter));
            }
        };
    }
}

/** The slugs and custom domains the switcher finds each tenant on. */
function addresses(): SubdomainAddresses
{
    $slugs = ['acme' => 'acme', 'globex' => 'globex', 'tenant-admin' => 'admin', 'tenant-www' => 'www', 'umbrella' => 'umbrella'];

    return new SubdomainAddresses(
        ROOT,
        static fn (string $tenant): ?string => $slugs[$tenant] ?? null,
        customDomain: static fn (string $tenant): ?string => $tenant === 'initech' ? 'initech.io' : null,
    );
}

/**
 * The application with the switcher pages, their policy and the real
 * PolicyGate in front of every check, m-1 a customer of acme, globex and
 * initech by the fixture's memberships.
 *
 * @param array<class-string, object> $instances beside the switchboard
 * @param list<class-string> $pages beside the switcher pages
 */
function selfServiceApp(
    ?Database $database = null,
    bool $addressed = true,
    ?RecordedActivity $activity = null,
    array $instances = [],
    array $pages = [],
    array $queries = [],
    array $global = [SignIn::class],
): App {
    $database ??= new Database();
    $orm = new Orm($database->connection, new IdentityMap('self-service-test'), Tenancy::tree());
    $auth = new AuthContext();
    $sessions = new Sessions();
    $switcher = new TenantSwitcher($orm, $auth, new Customers(), $sessions, static fn (Request $r): string => SESSION, realms());
    $switchboard = new TenantSwitchboard(
        $orm,
        $auth,
        new Customers(),
        $activity ?? new RecordedActivity(),
        realms(),
        $addressed ? addresses() : null,
        $switcher,
    );

    $container = new Container();
    $policies = new PolicyRegistry($container->resolver());
    $policies->register(TenantAdministration::class, AdministrationRules::class);
    $classes = [SwitchOrganization::class, PlatformSwitch::class, ...$pages];

    return new App(
        $database->connection,
        global: $global,
        routes: $classes,
        actions: $classes,
        gate: new PolicyGate($policies, $auth),
        policies: $policies,
        instances: [
            TenantSwitchboard::class => $switchboard,
            TenantSwitcher::class => $switcher,
            Sessions::class => $sessions,
            Memberships::class => new Customers(),
            ...$instances,
        ],
        panelRegistry: new PanelRegistry(new PanelDefinition('customer')),
        memberships: new Customers(),
        queries: $queries,
        redirectable: ['{tenant}.{root}'],
        orm: $orm,
        auth: $auth,
        container: $container,
    );
}

/**
 * A multi-tenant application with all of the self-service pages, wired by
 * the kit as an application wires them: tenants and members in the kit's
 * own tables -- acme (Ann the owner, Bob an admin, Cy a member), globex
 * (Eve the owner, Ann a member) and initech (Dee the owner) -- custom
 * domains in its table, DNS the test's.
 */
final class AdminWorld
{
    public readonly App $app;
    public readonly FakeDns $dns;
    public readonly TenantSelfService $kit;
    public readonly RecordedActivity $activity;
    public readonly SentInvites $invites;
    public readonly DatabaseTenants $tenants;
    public readonly DatabaseTenantMembers $members;
    public readonly TenantDomains $domains;
    public readonly People $people;
    public readonly Orm $orm;
    public readonly \Tinvar\Events\EventFake $events;
    public readonly \Tinvar\Identity\ArrayRememberedConsents $remembered;

    /** @var CarbonImmutable now, as every clock here reads it */
    public static CarbonImmutable $now;

    public ?\Tinvar\Audit\AuditLog $audit = null;

    /** Who is signed in, for a test calling the kit's services directly. */
    public readonly AuthContext $auth;

    public function __construct(?PdoConnection $connection = null, int $limit = 3, bool $addressed = true, bool $audited = false)
    {
        self::$now = CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC');
        $database = new Database($connection);
        $connection = $database->connection;
        $schema = new Schema($connection, \Tinvar\Orm\Schema\Dialect::for($connection));
        DatabaseTenants::define($schema);
        $connection->execute('CREATE TABLE tenant_members (tenant_id VARCHAR(191) NOT NULL, user_id VARCHAR(191) NOT NULL, panel VARCHAR(64) NOT NULL, role VARCHAR(64) NOT NULL, created_at VARCHAR(40) NOT NULL, PRIMARY KEY (tenant_id, panel, user_id))');
        TenantDomains::define($schema);

        $this->people = new People();
        $this->orm = new Orm($connection, new IdentityMap('self-service-admin'), Tenancy::tree());
        $this->tenants = new DatabaseTenants($connection);
        foreach (['acme' => 'Acme', 'globex' => 'Globex', 'initech' => 'Initech'] as $id => $name) {
            $this->tenants->create($name, $id, id: $id);
        }
        $this->members = new DatabaseTenantMembers($this->orm, $this->people);
        foreach ([['acme', 'm-1', 'owner'], ['acme', 'm-2', 'admin'], ['acme', 'm-3', 'member'], ['globex', 'm-9', 'owner'], ['globex', 'm-1', 'member'], ['initech', 'm-4', 'owner']] as [$tenant, $user, $role]) {
            $this->members->grant($tenant, 'customer', $user, $role);
        }

        $auth = $this->auth = new AuthContext();
        $this->dns = new FakeDns();
        $this->activity = new RecordedActivity();
        $this->events = new \Tinvar\Events\EventFake($this->orm);
        $this->remembered = new \Tinvar\Identity\ArrayRememberedConsents();
        $this->invites = new SentInvites();
        $this->domains = new TenantDomains($this->orm, clock: static fn (): CarbonImmutable => self::$now);
        $directory = null;
        $domains = $this->domains;
        $tenants = $this->tenants;
        $realms = new Realms(
            Realm::platform('https://' . ROOT),
            [Realm::platform('https://admin.' . ROOT, panel: 'admin')],
            static function (string $host) use ($domains, $tenants): ?Realm {
                if (str_ends_with($host, '.' . ROOT)) {
                    $tenant = $tenants->tenantForSlug(substr($host, 0, -\strlen('.' . ROOT)));

                    return $tenant === null ? null : Realm::platform("https://{$host}", $tenant);
                }

                return $host === 'initech.io' ? Realm::domain('https://initech.io', 'initech', 'customer') : $domains->realm($host, 'customer');
            },
        );
        $links = EmailLinks::fromKey(KEY, new ArrayEmailLinkStore(), $this->people);
        if ($audited) {
            \Tinvar\Audit\AuditTable::define($schema);
            $this->audit = new \Tinvar\Audit\AuditLog($this->orm, $auth, KEY);
        }

        $this->kit = new TenantSelfService(
            orm: $this->orm,
            auth: $auth,
            memberships: $this->members,
            realms: $realms,
            profiles: $this->tenants,
            members: $this->members,
            emailLinks: $links,
            users: $this->people,
            canonical: new CanonicalOrigins('https://' . ROOT),
            addresses: $addressed ? new SubdomainAddresses(ROOT, $this->tenants->slugOf(...), customDomain: $this->domains->primaryFor(...)) : null,
            invites: $this->invites,
            domains: $this->domains,
            throttles: new ArrayThrottleStore(),
            domainsConfig: new CustomDomainsConfig(targets: ['domains.example.test'], platform: [ROOT], grace: 3600, limit: $limit),
            dns: $this->dns,
            activity: $audited ? null : $this->activity,
            audit: $this->audit,
            events: $this->events,
            remembered: $this->remembered,
        );
        $this->invites->invitations = $this->kit->invitations;
        $this->invites->links = $this->kit->links;

        $container = new Container();
        $this->kit->register($container);
        $policies = new PolicyRegistry($container->resolver());
        $policies->register(TenantAdministration::class, AdministrationRules::class);
        $pages = [OrganizationDomains::class, OrganizationSettings::class, OrganizationInvitation::class, SwitchOrganization::class];

        $this->app = new App(
            $connection,
            global: [SignInAs::class],
            routes: $pages,
            actions: $pages,
            gate: new PolicyGate($policies, $auth),
            policies: $policies,
            instances: [
                Memberships::class => $this->members,
                TenantMemberships::class => $this->members,
                AccountUsers::class => $this->people,
                UserLookup::class => $this->people,
            ],
            panelRegistry: new PanelRegistry(new PanelDefinition('customer')),
            memberships: $this->members,
            queries: [OrganizationDomainStatuses::class],
            redirectable: ['{tenant}.{root}'],
            orm: $this->orm,
            auth: $auth,
            container: $container,
        );
        // The application's own lookups read the kit's tables, as the docs wire them.
        $this->app->directory->slugs = ['acme' => 'acme', 'globex' => 'globex', 'initech' => 'initech'];
        $this->app->directory->suspended = [];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers more headers: `x-impersonator`, `x-api-token`
     */
    public function act(string $action, array $body, string $as = 'm-1', string $host = 'acme.example.test', array $headers = []): Response
    {
        return $this->app->post("/_action/{$action}", $host, $body, ['x-user' => $as, ...$headers]);
    }

    /** @return array<string, mixed> */
    public function page(string $path, string $as = 'm-1', string $host = 'acme.example.test', array $query = []): array
    {
        $response = $this->app->get($path . ($query === [] ? '' : '?' . http_build_query($query)), $host, ['x-user' => $as]);
        expect($response->status)->toBe(200, $response->body());

        return App::json($response)['page'];
    }

    public function status(string $path, string $as = 'm-1', string $host = 'acme.example.test'): int
    {
        return $this->app->get($path, $host, ['x-user' => $as])->status;
    }
}
