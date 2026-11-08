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
use Tinvar\Events\Events;
use Tinvar\Identity\Realms;
use Tinvar\Identity\RememberedConsents;
use Tinvar\Identity\SystemDnsResolver;
use Tinvar\Identity\TlsAskEndpoint;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Observability\ProbeAccess;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Routing\Domains;
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
 *         domains: new TenantDomains($orm), throttles: $throttleStore,
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
 * the ownership check, the re-verification with its graces and schedule
 * (TenantDomainReverification, run by ReverifyTenantDomains, ADR 0198) --
 * from CustomDomainsConfig, and Caddy's TLS `ask` endpoint, which it
 * registers in the container: the Kernel mounts the one it finds there, so
 * a certificate is asked about the moment custom domains are on. Without
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

    /** The custom domains proved again on the schedule (ADR 0198); null without custom domains. */
    public ?TenantDomainReverification $reverification;

    public ?TlsAskEndpoint $tlsAsk;

    /** The slugs a tenant may take: what a sign-up holds a new tenant's slug to, as the settings do. */
    public SlugRules $slugs;

    /** Where every change is recorded: the given activity, the audit log, or nowhere. */
    public TenantActivity $activity;

    /** The memberships it was given, which the switcher lists, every panel checks and TenantAdministrationPolicy reads. */
    public Memberships&TenantMemberships $memberships;

    /** The users it was given, which SendTenantInvite reads an invitee by. */
    public UserLookup $users;

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
     * @param Domains|null $routing the application's Domains, where it routes by host: every
     *        label of every host it routes is reserved as a slug, as the realms' hosts' are
     * @param list<string> $reservedSlugs slugs beside `admin`, `api`, `www` and the platform's hosts' that
     *        no tenant may take
     * @param string $invitePath the path an invite lands on: the application's AcceptInvitationPage
     * @param TenantActivity|null $activity where every change is recorded; the audit log's when
     *        $audit is given (AuditedTenantActivity), nowhere otherwise
     * @param AuditLog|null $audit the application's audit log (ADR 0156)
     * @param bool $localHttp emailed links and addresses on http://localhost, in development: give
     *        EmailLinks and CanonicalOrigins the same flag, so a one-host app whose platform origin
     *        is http://app.localhost:PORT builds its invites there (ADR 0182)
     * @param Events|null $events where a custom domain's change of state is dispatched, as
     *        TenantDomainChanged, for the application to tell the tenant's admins (ADR 0198)
     * @param RememberedConsents|null $remembered the handoff's remembered consents: a domain that
     *        lapses is forgotten there for everyone (ADR 0198)
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
        ?Domains $routing = null,
        string $invitePath = '/invite',
        bool $localHttp = false,
        ?Events $events = null,
        ?RememberedConsents $remembered = null,
    ) {
        $this->memberships = $memberships;
        $this->users = $users;
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
            $this->slugs = SlugRules::forPlatform($realms, $routing, $reservedSlugs),
            $this->roles,
            $this->activity,
        );

        if ($domains === null) {
            $this->claims = null;
            $this->verification = null;
            $this->reverification = null;
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
        $this->reverification = new TenantDomainReverification(
            $orm,
            $domains,
            $verifier,
            new DomainOwnership($dns),
            $config->grace,
            $config->proofGrace,
            $config->interval,
            $config->release,
            $this->activity,
            $events,
            $remembered,
            $realms,
            $auth,
            $audit,
        );
        // The framework's check of where a domain points -- Caddy's ask, and
        // VerifyCustomDomains where it is still listed -- writes through the
        // re-verification, so its changes are recorded and announced too.
        $this->verification = new CustomDomainVerification($this->reverification, $verifier, $config->grace, $domains->now(...));
        $this->claims = new TenantDomainClaims(
            $domains,
            $this->reverification,
            $config->targets,
            $config->addresses,
            $config->platform === [] ? $platformHosts : $config->platform,
            $realms,
            $config->limit,
            $this->activity,
        );
        $this->tlsAsk = new TlsAskEndpoint($realms, $this->reverification, $this->verification, $throttles, $probeAccess ?? new ProbeAccess());
    }

    /**
     * The tables the kit's stores keep: the tenants and their slug history,
     * their members and their custom domains. An application with its own
     * tenants or memberships makes only the rest.
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
    public const array TABLES = [TenantDomains::TABLE, DatabaseTenantMembers::TABLE, DatabaseTenants::SLUGS_TABLE, DatabaseTenants::TABLE];

    /**
     * What the pages, the policy, the invite job and the Kernel are built
     * with. The memberships and the user lookup it was given too, unless the
     * application registered its own already: the accounts kit registers
     * the lookup first, where the application uses it.
     */
    public function register(Container $container): Container
    {
        foreach ([Memberships::class => $this->memberships, TenantMemberships::class => $this->memberships, UserLookup::class => $this->users] as $class => $instance) {
            if (!$container->registered($class)) {
                $container->instance($class, $instance);
            }
        }

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

        if ($this->domains !== null && $this->claims !== null && $this->verification !== null && $this->reverification !== null && $this->tlsAsk !== null) {
            $container
                ->instance(TenantDomains::class, $this->domains)
                ->instance(CustomDomains::class, $this->reverification)
                // What ReverifyTenantDomains asks for.
                ->instance(TenantDomainReverification::class, $this->reverification)
                ->instance(TenantDomainClaims::class, $this->claims)
                ->instance(CustomDomainVerification::class, $this->verification)
                // The Kernel mounts the TLS ask it finds registered here.
                ->instance(TlsAskEndpoint::class, $this->tlsAsk);
        }

        return $container;
    }
}
