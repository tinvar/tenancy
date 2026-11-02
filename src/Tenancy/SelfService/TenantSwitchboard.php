<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\SubtreeMemberships;
use Tinvar\Auth\TenantMemberships;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Identity\HandoffEndpoint;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Orm\Orm;
use Tinvar\Tenancy\TenantSwitching;

/**
 * The tenant switcher page's service (ADR 0159): every tenant the signed-in
 * user may go to from here, and the switch to one -- by a new session on
 * this host, or by the cross-host handoff to a tenant's own.
 *
 * Built on TenantSwitcher, which it leaves to hold a session switch to its
 * rules (a membership of the panel, inside the subtree of the tenant the
 * host belongs to, a new session id), and on HandoffEndpoint, which holds a
 * switch to another host to its own (the platform signs the user in there
 * with a one-time code bound to their session and auth epoch, after
 * checking the membership again, staff, and -- for a custom domain -- the
 * person's consent).
 *
 * Which tenants are offered, and how:
 *
 * - **The tenant the request runs as**, marked current.
 * - **A tenant on a host of its own** (TenantAddresses) that is not this
 *   one: by the handoff, when its origin is a realm the application serves
 *   that bounds it, and this host is one of the platform's. On a tenant's
 *   custom domain nothing on another host is offered: "the tenant switcher
 *   is limited to that subtree" (tinvar-spec.md), and a domain someone else
 *   runs is not told which other tenants its visitor belongs to.
 * - **A tenant with no host of its own, or one on this host**: by a new
 *   session, as TenantSwitcher's choices() lists them -- none, where the
 *   application gave no TenantSwitcher (sessions that never change tenant).
 *
 * Each one a membership of the panel confirms, asked as that tenant. A
 * switch is checked against the same list, built again for the request
 * that asks, and every refusal is TenantSwitcher's 403,
 * `tenant_switch_refused` -- the same for a tenant that exists elsewhere,
 * one that exists nowhere, and one the user left.
 */
final readonly class TenantSwitchboard
{
    private SubtreeMemberships $members;

    /**
     * @param Memberships&TenantMemberships $memberships the application's, listed and confirmed
     * @param Realms|null $realms the application's hosts; without them nothing on another host
     *        is offered, since nothing could check where a handoff would go
     * @param TenantAddresses|null $addresses where each tenant is served; none, and every
     *        tenant is switched to on this host
     * @param TenantSwitching|null $switcher a session switch on this host; none, and only
     *        tenants on hosts of their own are offered
     * @param TenantProfiles|null $profiles the tenants' names; none, and each is shown by id
     */
    public function __construct(
        private Orm $orm,
        private AuthContext $auth,
        Memberships&TenantMemberships $memberships,
        private TenantActivity $activity = new UnrecordedTenantActivity(),
        private ?Realms $realms = null,
        private ?TenantAddresses $addresses = null,
        private ?TenantSwitching $switcher = null,
        private ?TenantProfiles $profiles = null,
    ) {
        $this->members = new SubtreeMemberships($orm, $memberships);
    }

    /**
     * Every tenant the signed-in user may go to from this request, with a
     * membership of $panel in each, the current one first.
     *
     * @return list<TenantChoice>
     * @throws HttpException 401 for nobody signed in; 404 for a host that is no realm
     */
    public function choices(Request $request, string $panel): array
    {
        $user = $this->auth->user() ?? throw self::unauthenticated();
        $here = $this->here($request);
        $current = $this->orm->tenant();

        $local = $this->switcher?->choices($request, $panel) ?? [];
        $choices = [];
        if ($current !== '' && $this->members->holds($user, $panel, $current)) {
            $choices[$current] = new TenantChoice($current, $this->name($current), TenantSwitchVia::Current);
        }

        foreach ($this->members->tenants($user, $panel, null) as $tenant) {
            if (isset($choices[$tenant])) {
                continue;
            }

            $away = $this->elsewhere($tenant, $here, $panel);
            if ($away !== null) {
                $choices[$tenant] = new TenantChoice($tenant, $this->name($tenant), TenantSwitchVia::Handoff, $away->origin);
            } elseif (\in_array($tenant, $local, true) && !$this->onAnotherHost($tenant, $here)) {
                $choices[$tenant] = new TenantChoice($tenant, $this->name($tenant), TenantSwitchVia::Session);
            }
        }

        return array_values($choices);
    }

    /**
     * Switches the signed-in user to $tenant: where the browser goes next.
     *
     * @param string $landing the root-relative path the tenant opens on
     * @throws HttpException 401 for nobody signed in; 403 `tenant_switch_refused` for a tenant
     *         not on offer here; 404 for a host that is no realm
     */
    public function switch(Request $request, string $tenant, string $panel, string $landing = '/'): TenantDestination
    {
        $user = $this->auth->user() ?? throw self::unauthenticated();
        if (!str_starts_with($landing, '/') || str_starts_with($landing, '//') || \strlen($landing) > HandoffEndpoint::MAX_RETURN) {
            throw new \InvalidArgumentException('A tenant is opened on a root-relative path of its own host.');
        }

        $choice = null;
        foreach ($this->choices($request, $panel) as $offered) {
            if ($offered->tenant === $tenant) {
                $choice = $offered;
            }
        }
        if ($choice === null) {
            throw new HttpException(403, 'The tenant switch is refused: that tenant is not one you can switch to from here.', 'tenant_switch_refused');
        }

        $from = $this->orm->tenant();
        $destination = match ($choice->via) {
            TenantSwitchVia::Current => new TenantDestination(TenantSwitchVia::Current, $landing),
            TenantSwitchVia::Handoff => new TenantDestination(
                TenantSwitchVia::Handoff,
                $choice->origin . HandoffEndpoint::START . '?' . http_build_query(['return' => $landing], '', '&', PHP_QUERY_RFC3986),
            ),
            // TenantSwitcher holds it to its rules again, and refuses as
            // above; a switcher is there, or no choice was a session's.
            TenantSwitchVia::Session => new TenantDestination(
                TenantSwitchVia::Session,
                $landing,
                ($this->switcher ?? throw new \LogicException('A session switch was offered with no switcher.'))->switch($request, $tenant, $panel),
            ),
        };

        if ($destination->via !== TenantSwitchVia::Current) {
            $this->activity->record(TenantChange::Switched, $tenant, $user, ['from' => $from, 'via' => $destination->via->value]);
        }

        return $destination;
    }

    /**
     * The realm of this request's host, or null where there are no realms.
     *
     * @throws HttpException 404 for a host that is none
     */
    private function here(Request $request): ?Realm
    {
        if ($this->realms === null) {
            return null;
        }

        return $this->realms->forRequest($request)
            ?? throw new HttpException(404, 'This host is no realm of the application\'s.', 'tenant_switch_unknown');
    }

    /**
     * The realm on another host that $tenant is served on, when a handoff
     * there may be offered from $here; null otherwise.
     */
    private function elsewhere(string $tenant, ?Realm $here, string $panel): ?Realm
    {
        // From a tenant's custom domain, nothing on another host: the
        // switcher there is held to the domain's subtree.
        if ($here === null || $here->isCustom() || $this->realms === null) {
            return null;
        }

        $realm = $this->addressed($tenant);
        if ($realm === null || $realm->host === $here->host || $this->realms->isAuthority($realm)) {
            return null;
        }
        // A handoff into a custom domain is into its panel alone.
        if ($realm->isCustom() && $realm->panel !== $panel) {
            return null;
        }

        return $realm;
    }

    /** Whether $tenant is served on a host of its own that is not this one. */
    private function onAnotherHost(string $tenant, ?Realm $here): bool
    {
        $realm = $this->addressed($tenant);

        return $realm !== null && $here !== null && $realm->host !== $here->host;
    }

    /**
     * The realm the application says $tenant is served on, when it is one it
     * serves and it is $tenant's own: a handoff lands in the realm's tenant
     * exactly, so a realm of a tenant above would land the user there
     * instead. Null otherwise.
     */
    private function addressed(string $tenant): ?Realm
    {
        $origin = $this->addresses?->originFor($tenant);
        if ($origin === null || $origin === '' || $this->realms === null) {
            return null;
        }

        $realm = $this->realms->forOrigin($origin);
        if ($realm === null || $realm->tenant !== $tenant) {
            return null;
        }

        return $realm;
    }

    private function name(string $tenant): string
    {
        return $this->profiles?->profile($tenant)->name ?? $tenant;
    }

    private static function unauthenticated(): HttpException
    {
        return new HttpException(401, 'Sign in to switch between your organizations.', 'unauthenticated');
    }
}
