<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Audit\AuditLog;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\TenantMemberships;
use Tinvar\Auth\UserLookup;
use Tinvar\Container\Container;
use Tinvar\Http\ThrottleStore;
use Tinvar\Identity\CanonicalOrigins;
use Tinvar\Identity\CustomDomains;
use Tinvar\Identity\CustomDomainVerification;
use Tinvar\Identity\DnsResolver;
use Tinvar\Identity\DnsTextResolver;
use Tinvar\Identity\DomainVerifier;
use Tinvar\Identity\EmailLinks;
use Tinvar\Identity\Invitations;
use Tinvar\Identity\InvitePolicy;
use Tinvar\Identity\Realms;
use Tinvar\Identity\SystemDnsResolver;
use Tinvar\Identity\TlsAskEndpoint;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Observability\ProbeAccess;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Tenancy\TenantSwitching;

/**
 * The tenant self-service pages' services, built from what only the
 * application knows and registered in its container (ADR 0161), as
 * AccountsKit is for accounts:
 *
 *     $selfService = new TenantSelfService(
 *         orm: $orm, auth: $auth, memberships: $members, realms: $realms,
 *         profiles: $tenants, members: $members, emailLinks: $links, users: $users,
 *         canonical: $origins, addresses: new SubdomainAddresses(...),
 *         domains: new TenantDomains($connection), throttles: $throttleStore,
 *         dispatcher: $dispatcher,
 *     );
 *     $selfService->register($container);
 *
 * The pages themselves are the application's: classes extending
 * TenantSwitchPage, CustomDomainsPage, TenantSettingsPage and
 * AcceptInvitationPage, each with its #[Page], found by discovery, and a
 * policy for TenantAdministration. `TenantPagesScaffold` writes them.
 *
 * Given its custom domains, the kit builds their checks -- the DNS check,
 * the ownership check, the re-verification with its grace -- from
 * CustomDomainsConfig, and Caddy's TLS `ask` endpoint, which it registers
 * in the container: the Kernel mounts the one it finds there, so a
 * certificate is asked about the moment custom domains are on. Without
 * them, there is no domains page, and nothing answers /_tls/ask.
 */
final readonly class TenantSelfService
{
    public TenantSwitchboard $switchboard;

    public TenantLinks $links;

    public Invitations $invitations;

    public InvitePolicy $invitePolicy;

    public TenantSettings $settings;

    public ?TenantDomainClaims $claims;

    public ?CustomDomainVerification $verification;

    public ?TlsAskEndpoint $tlsAsk;

    /** Where every change is recorded: the given activity, the audit log, or nowhere. */
    public TenantActivity $activity;

    /**
     * @param Memberships&TenantMemberships $memberships the application's memberships, which
     *        the switcher lists and every panel checks -- DatabaseTenantMembers, or its own
     * @param TenantSwitching|null $switcher a session switch on one host (TenantSwitcher); none,
     *        and only tenants on hosts of their own are offered
     * @param TenantInvites|null $invites how an invite is sent; QueuedTenantInvites on
     *        $dispatcher unless given
     * @param TenantDomains|null $domains the custom domains' table; none, and no tenant adds one
     * @param ThrottleStore|null $throttles the store the application's throttles count in, which
     *        the TLS ask claims a domain's one check at a time in: needed with $domains
     * @param list<string> $reservedSlugs slugs beside `admin`, `www` and the platform's hosts' that
     *        no tenant may take
     * @param string $invitePath the path an invite lands on: the application's AcceptInvitationPage
     * @param TenantActivity|null $activity where every change is recorded; the audit log's when
     *        $audit is given (AuditedTenantActivity), nowhere otherwise
     * @param AuditLog|null $audit the application's audit log (ADR 0156)
     * @param bool $localHttp emailed links and addresses on http://localhost, in development
     */
    public function __construct(
        Orm $orm,
        AuthContext $auth,
        Memberships&TenantMemberships $memberships,
        public Realms $realms,
        public TenantProfiles $profiles,
        public TenantMembers $members,
        EmailLinks $emailLinks,
        UserLookup $users,
        CanonicalOrigins $canonical,
        public ?TenantAddresses $addresses = null,
        ?TenantSwitching $switcher = null,
        ?TenantInvites $invites = null,
        ?Dispatcher $dispatcher = null,
        public ?TenantDomains $domains = null,
        ?ThrottleStore $throttles = null,
        ?CustomDomainsConfig $domainsConfig = null,
        ?DnsResolver $dns = null,
        ?ProbeAccess $probeAccess = null,
        public TenantRoles $roles = new TenantRoles(),
        ?TenantActivity $activity = null,
        ?AuditLog $audit = null,
        array $reservedSlugs = [],
        string $invitePath = '/invite',
        bool $localHttp = false,
    ) {
        $this->activity = $activity ?? ($audit === null ? new UnrecordedTenantActivity() : new AuditedTenantActivity($audit));
        $this->switchboard = new TenantSwitchboard($orm, $auth, $memberships, $this->activity, $realms, $addresses, $switcher, $profiles);
        $this->links = new TenantLinks($canonical, $addresses, $localHttp);
        $this->invitePolicy = new RoleInvitePolicy($members, $this->roles);
        $this->invitations = new Invitations($emailLinks, $this->invitePolicy, $users, $invitePath);

        $invites ??= $dispatcher === null
            ? throw new \InvalidArgumentException('Invites are sent off the request: give the self-service kit the Dispatcher, or TenantInvites of your own.')
            : new QueuedTenantInvites($dispatcher);
        $platformHosts = [$realms->authority->host];
        $this->settings = new TenantSettings(
            $profiles,
            $members,
            $this->invitations,
            $this->invitePolicy,
            $invites,
            new SlugRules($reservedSlugs, $platformHosts, self::rootOf($realms->authority->host)),
            $this->roles,
            $this->activity,
        );

        if ($domains === null) {
            $this->claims = null;
            $this->verification = null;
            $this->tlsAsk = null;

            return;
        }
        if ($throttles === null) {
            throw new \InvalidArgumentException(
                'Custom domains are checked one at a time across every worker, in the store the throttles count in: give the self-service kit that ThrottleStore.',
            );
        }

        $config = $domainsConfig ?? CustomDomainsConfig::load();
        $dns ??= new SystemDnsResolver();
        if (!$dns instanceof DnsTextResolver) {
            throw new \InvalidArgumentException('A tenant proves a custom domain with a TXT record: give a DnsResolver that is a DnsTextResolver too, as SystemDnsResolver is.');
        }
        $verifier = new DomainVerifier($config->targets, $config->addresses, $dns);
        $this->verification = new CustomDomainVerification($domains, $verifier, $config->grace, $domains->now(...));
        $this->claims = new TenantDomainClaims(
            $domains,
            $verifier,
            new DomainOwnership($dns),
            $this->verification,
            $config->targets,
            $config->addresses,
            $config->platform === [] ? $platformHosts : $config->platform,
            $realms,
            $config->limit,
            $this->activity,
        );
        $this->tlsAsk = new TlsAskEndpoint($realms, $domains, $this->verification, $throttles, $probeAccess ?? new ProbeAccess());
    }

    /**
     * The tables the kit's stores keep: the tenants, their members and their
     * custom domains. An application with its own tenants or memberships
     * makes only the rest.
     *
     * @param class-string<\Tinvar\Orm\Model> $userModel
     */
    public static function migrate(Schema $schema, string $userModel, bool $ifNotExists = false): void
    {
        DatabaseTenants::define($schema, ifNotExists: $ifNotExists);
        DatabaseTenantMembers::define($schema, $userModel, ifNotExists: $ifNotExists);
        TenantDomains::define($schema, ifNotExists: $ifNotExists);
    }

    /** The tables migrate() makes, last first, for a migration's down(). */
    public const array TABLES = [TenantDomains::TABLE, DatabaseTenantMembers::TABLE, DatabaseTenants::TABLE];

    /** What the pages, the policy, the invite job and the Kernel are built with. */
    public function register(Container $container): Container
    {
        $container
            ->instance(TenantSwitchboard::class, $this->switchboard)
            ->instance(TenantLinks::class, $this->links)
            ->instance(Invitations::class, $this->invitations)
            ->instance(InvitePolicy::class, $this->invitePolicy)
            ->instance(TenantSettings::class, $this->settings)
            ->instance(TenantProfiles::class, $this->profiles)
            ->instance(TenantMembers::class, $this->members)
            ->instance(TenantRoles::class, $this->roles)
            ->instance(TenantActivity::class, $this->activity);

        if ($this->domains !== null && $this->claims !== null && $this->verification !== null && $this->tlsAsk !== null) {
            $container
                ->instance(TenantDomains::class, $this->domains)
                ->instance(CustomDomains::class, $this->domains)
                ->instance(TenantDomainClaims::class, $this->claims)
                ->instance(CustomDomainVerification::class, $this->verification)
                // The Kernel mounts the TLS ask it finds registered here.
                ->instance(TlsAskEndpoint::class, $this->tlsAsk);
        }

        return $container;
    }

    /** The domain the platform's own host is under: `example.com` for `app.example.com`, the host itself for two labels. */
    private static function rootOf(string $host): string
    {
        $labels = explode('.', $host);

        return \count($labels) > 2 ? implode('.', \array_slice($labels, 1)) : $host;
    }
}
