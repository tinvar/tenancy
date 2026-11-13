<?php

declare(strict_types=1);

namespace Tinvar\Tests\Tenancy\Fixtures\OneHostInvites;

use Tinvar\Accounts\AccountsKit;
use Tinvar\Accounts\AccountUsers;
use Tinvar\Accounts\StartSession;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Policy;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Gate;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Auth\TenantMemberships;
use Tinvar\Auth\UserLookup;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\CanonicalOrigins;
use Tinvar\Identity\Invitations;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tenancy\AccountTenantSessions;
use Tinvar\Tenancy\SelfService\DatabaseTenantMembers;
use Tinvar\Tenancy\SelfService\DatabaseTenants;
use Tinvar\Tenancy\SelfService\Http\AcceptInvitationPage;
use Tinvar\Tenancy\SelfService\Http\TenantSettingsPage;
use Tinvar\Tenancy\SelfService\TenantActivity;
use Tinvar\Tenancy\SelfService\TenantAddresses;
use Tinvar\Tenancy\SelfService\TenantAdministration;
use Tinvar\Tenancy\SelfService\TenantAdministrationPolicy;
use Tinvar\Tenancy\SelfService\TenantChange;
use Tinvar\Tenancy\SelfService\TenantInvites;
use Tinvar\Tenancy\SelfService\TenantLinks;
use Tinvar\Tenancy\SelfService\TenantSelfService;
use Tinvar\Tenancy\Tenancy;
use Tinvar\Tenancy\TenantSwitcher;
use Tinvar\Tests\Accounts\Fixtures\Member;
use Tinvar\Tests\Accounts\Fixtures\MemberApp;
use Tinvar\Tests\Accounts\Fixtures\Members;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/framework') . '/tests/Accounts/Fixtures/Members.php';

/**
 * An application serving every tenant on one host, app.test, with the
 * tenant self-service kit and the accounts kit (ADR 0181, ADR 0182): the
 * session holds the tenant its user switched to, the settings page runs as
 * it, and the invitation page declares no tenant source -- an invite's
 * link names its own, under its MAC.
 *
 * Tenants acme, globex and initech have no host of their own; umbrella has
 * one, umbrella.app.test, whose invites land there and not here.
 */
#[Page('/settings', view: 'tenancy/settings', name: 'tenancy.settings'), Panel('customer')]
final class OrganizationSettings extends TenantSettingsPage {}

/** What a one-host application declares: where the page is, and nothing about its tenant. */
#[Page('/invite', view: 'tenancy/invitation', name: 'tenancy.invitation')]
final class OrganizationInvitation extends AcceptInvitationPage {}

final readonly class TenantData extends Data
{
    public function __construct(public string $tenant) {}
}

/** The tenant a request runs as, in the customer panel: a member's, of the session's tenant. */
#[Page('/whoami', view: 'whoami', name: 'whoami'), Panel('customer')]
final readonly class WhoAmI
{
    public function __construct(private Orm $orm) {}

    public function __invoke(): TenantData
    {
        return new TenantData($this->orm->tenant());
    }
}

/** The application's switcher action, as in one_host_app.php. */
final readonly class Organizations
{
    public function __construct(private TenantSwitcher $switcher) {}

    #[Action, NoAuthorization(reason: 'TenantSwitcher checks the membership itself')]
    public function switchTo(string $tenant, Request $request): ActionResult
    {
        $this->switcher->switch($request, $tenant, 'customer');

        return ActionResult::redirect('/whoami');
    }
}

#[Policy(TenantAdministration::class)]
final class AdministrationRules extends TenantAdministrationPolicy {}

/** Every change recorded, with the tenant the request ran as when it was. */
final class Activity implements TenantActivity
{
    /** @var list<array{change: string, tenant: string, runningAs: string}> */
    public array $records = [];

    public function __construct(private readonly Orm $orm) {}

    public function record(TenantChange $change, string $tenant, ?Authenticatable $actor, array $details = []): void
    {
        $this->records[] = ['change' => $change->value, 'tenant' => $tenant, 'runningAs' => $this->orm->tenant()];
    }
}

/** Invites made at once, as the queued job makes them on the worker. */
final class Invites implements TenantInvites
{
    /** @var list<string> the URLs, oldest first */
    public array $urls = [];

    public ?Invitations $invitations = null;

    public ?TenantLinks $links = null;

    public function send(Authenticatable $inviter, string $email, string $tenant, string $panel, string $role): void
    {
        \assert($this->invitations !== null && $this->links !== null);
        $this->urls[] = $this->invitations->invite($inviter, $email, $tenant, $panel, $role, $this->links->originFor($tenant))['url'];
    }

    public function lastToken(): string
    {
        parse_str((string) parse_url(end($this->urls) ?: '', PHP_URL_QUERY), $query);

        return \is_string($query['token'] ?? null) ? $query['token'] : '';
    }
}

/** umbrella alone has a host of its own; every other tenant is served on app.test. */
final class Addresses implements TenantAddresses
{
    public function originFor(string $tenant): ?string
    {
        return $tenant === 'umbrella' ? 'https://umbrella.app.test' : null;
    }
}

final class App
{
    public readonly MemberApp $accounts;
    public readonly Kernel $kernel;
    public readonly Orm $orm;
    public readonly DatabaseTenantMembers $members;
    public readonly TenantSelfService $kit;
    public readonly Activity $activity;
    public readonly Invites $invites;

    public function __construct(?Connection $connection = null)
    {
        $connection ??= PdoConnection::sqlite(':memory:', 'one-host-invites');
        $schema = new Schema($connection, Dialect::for($connection));
        Members::define($schema);
        AccountsKit::migrate($schema, Member::class);
        DatabaseTenants::define($schema);
        DatabaseTenantMembers::define($schema, Member::class);

        $this->accounts = new MemberApp($connection, tenancy: Tenancy::tree());
        $this->orm = $this->accounts->orm;
        $kit = $this->accounts->kit;
        $auth = $this->accounts->auth;
        $people = new Members($this->orm);
        $tenants = new DatabaseTenants($connection);
        foreach (['acme' => 'Acme', 'globex' => 'Globex', 'initech' => 'Initech', 'umbrella' => 'Umbrella'] as $id => $name) {
            $tenants->create($name, $id, id: $id);
        }
        $this->members = new DatabaseTenantMembers($this->orm, $kit->lookup);
        $this->activity = new Activity($this->orm);
        $this->invites = new Invites();
        $switcher = new TenantSwitcher($this->orm, $auth, $this->members, new AccountTenantSessions($kit->sessions), $kit->sessionId(...));

        $this->kit = new TenantSelfService(
            orm: $this->orm,
            auth: $auth,
            memberships: $this->members,
            realms: new Realms(Realm::platform('https://app.test')),
            profiles: $tenants,
            members: $this->members,
            emailLinks: $kit->links,
            users: $kit->lookup,
            canonical: new CanonicalOrigins('https://app.test'),
            addresses: new Addresses(),
            switcher: $switcher,
            invites: $this->invites,
            throttles: new \Tinvar\Http\ArrayThrottleStore(),
            activity: $this->activity,
        );
        $this->invites->invitations = $this->kit->invitations;
        $this->invites->links = $this->kit->links;

        $panels = new PanelContext();
        $container = $this->kit->register($kit->register(new Container()))
            ->instance(Orm::class, $this->orm)
            ->instance(AuthContext::class, $auth)
            ->instance(PanelContext::class, $panels)
            ->instance(Memberships::class, $this->members)
            ->instance(TenantMemberships::class, $this->members)
            ->instance(AccountUsers::class, $people)
            ->instance(UserLookup::class, $kit->lookup)
            ->instance(TenantSwitcher::class, $switcher);
        $policies = new PolicyRegistry($container->resolver());
        $policies->register(TenantAdministration::class, AdministrationRules::class);
        $gate = new PolicyGate($policies, $auth);
        $container->instance(Gate::class, $gate);

        $pages = [OrganizationSettings::class, OrganizationInvitation::class, WhoAmI::class];
        $routes = new RouteRegistry();
        $routes->registerAll($pages);
        $actions = new ActionRegistry($container->resolver());
        $actions->registerAll([...$pages, Organizations::class]);

        $this->kernel = new Kernel(
            orm: $this->orm,
            routes: $routes,
            queries: new QueryRegistry($container->resolver()),
            actions: $actions,
            auth: $auth,
            policies: $policies,
            resolver: $container->resolver(),
            tenants: new Tenancy($container->resolver()),
            allowedQueries: static fn (Request $r): array => [],
            globalMiddleware: [StartSession::class],
            gate: $gate,
            panelRegistry: new PanelRegistry(new PanelDefinition('customer')),
            memberships: $this->members,
            panels: $panels,
            container: $container,
        );
    }

    /** A new account, and the cookie of a browser signed in as it on app.test, in no tenant. */
    public function person(string $email): string
    {
        $user = new Members($this->orm)->create($email, $email, password_hash('a passphrase long enough', PASSWORD_BCRYPT, ['cost' => 4]));

        return $this->accounts->kit->sessions->signedInCookie($user, new Request('GET', '/', ['host' => 'app.test']));
    }

    /** The cookie of another browser signed in as $email's account, in no tenant. */
    public function signIn(string $email): string
    {
        $user = new Members($this->orm)->findByEmail($email) ?? throw new \LogicException("No account for {$email}.");

        return $this->accounts->kit->sessions->signedInCookie($user, new Request('GET', '/', ['host' => 'app.test']));
    }

    public function idOf(string $email): string
    {
        return (string) new Members($this->orm)->findByEmail($email)?->authIdentifier();
    }

    /** The cookie of a browser no one is signed in on. */
    public function nobody(): string
    {
        return $this->accounts->kit->cookie->issue();
    }

    /** @param array<string, string> $query */
    public function get(string $path, string $cookie, array $query = []): Response
    {
        return $this->kernel->handle(
            new Request('GET', $path, ['host' => 'app.test'], $query, cookies: [$this->accounts->kit->cookie->name => $cookie]),
            static fn (LoadedRoute $loaded, Request $passed): Response => Response::make(200, json_encode(
                $loaded->page === null ? [] : get_object_vars($loaded->page),
                JSON_THROW_ON_ERROR,
            )),
        );
    }

    /** @param array<string, mixed> $body */
    public function act(string $action, array $body, string $cookie): Response
    {
        return $this->kernel->handle(
            new Request(
                'POST',
                "/_action/{$action}",
                ['host' => 'app.test', 'origin' => 'https://app.test', 'content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => 'csrf'],
                [],
                [],
                ['__Host-XSRF-TOKEN' => 'csrf', $this->accounts->kit->cookie->name => $cookie],
            ),
            static fn (): Response => Response::make(500, 'not a page'),
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** The session cookie $response set, or null for none. */
    public function cookieOf(Response $response): ?string
    {
        $line = $response->cookies()[$this->accounts->kit->cookie->name] ?? null;

        return $line !== null && preg_match('/^[^=]+=([^;]*);/', $line, $m) === 1 ? $m[1] : null;
    }

    /** The cookie of a browser signed in as $email and switched to $tenant, a member there. */
    public function in(string $tenant, string $cookie): string
    {
        $switched = $this->cookieOf($this->act('Organizations.switchTo', ['tenant' => $tenant], $cookie));
        \assert(\is_string($switched));

        return $switched;
    }

    /** @return array<string, mixed> */
    public static function json(Response $response): array
    {
        $decoded = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> the errors under $field */
    public static function errors(Response $response, string $field): array
    {
        $errors = self::json($response)['errors'][$field] ?? self::json($response)['error']['errors'][$field] ?? [];

        return \is_array($errors) ? array_values(array_map('strval', $errors)) : [];
    }
}
