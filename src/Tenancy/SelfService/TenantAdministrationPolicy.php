<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Membership;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelContext;
use Tinvar\Orm\Orm;

/**
 * Who may do what on the tenant self-service pages, by default (ADR 0155):
 * the policy for TenantAdministration that an application extends and
 * registers -- as is, or with any ability answered its own way.
 *
 *     #[Policy(TenantAdministration::class)]
 *     final class TenantAdministrationRules extends TenantAdministrationPolicy {}
 *
 * Every answer is about the tenant the request runs as (Orm::tenant()) and
 * the panel it is in (PanelContext), and every one is no for nobody signed
 * in, no tenant, or no panel:
 *
 * - switching needs only someone signed in -- the switcher holds every
 *   choice to a membership itself;
 * - seeing domains or settings needs a membership of the panel in the
 *   tenant;
 * - changing them needs one carrying the permission for it
 *   (TenantPermission), which the member's role grants;
 * - accepting an invite needs only the link (see acceptTenantInvite()).
 *
 * Each is a public method PolicyGate calls by the ability's name; override
 * one to answer differently, and call the parent to add to it.
 */
abstract class TenantAdministrationPolicy
{
    public function __construct(
        protected readonly Orm $orm,
        protected readonly Memberships $memberships,
        protected readonly PanelContext $panels,
    ) {}

    /** @param array<string, mixed> $arguments */
    public function switchTenant(?Authenticatable $user, string $subject, array $arguments = []): bool
    {
        return $user !== null;
    }

    /** @param array<string, mixed> $arguments */
    public function viewTenantDomains(?Authenticatable $user, string $subject, array $arguments = []): bool
    {
        return $this->membership($user) !== null;
    }

    /** @param array<string, mixed> $arguments */
    public function manageTenantDomains(?Authenticatable $user, string $subject, array $arguments = []): bool
    {
        return $this->membership($user)?->can(TenantPermission::Domains->value) === true;
    }

    /** @param array<string, mixed> $arguments */
    public function viewTenantSettings(?Authenticatable $user, string $subject, array $arguments = []): bool
    {
        return $this->membership($user) !== null;
    }

    /** @param array<string, mixed> $arguments */
    public function manageTenantSettings(?Authenticatable $user, string $subject, array $arguments = []): bool
    {
        return $this->membership($user)?->can(TenantPermission::Settings->value) === true;
    }

    /** @param array<string, mixed> $arguments */
    public function manageTenantMembers(?Authenticatable $user, string $subject, array $arguments = []): bool
    {
        return $this->membership($user)?->can(TenantPermission::Members->value) === true;
    }

    /**
     * Anyone holding the link: an invite names an address, not an account,
     * and Invitations checks the link -- its tenant, its origin, its expiry,
     * its single use, and the inviter's right to send it -- before it grants
     * anything, to that address's account alone.
     *
     * @param array<string, mixed> $arguments
     */
    public function acceptTenantInvite(?Authenticatable $user, string $subject, array $arguments = []): bool
    {
        return true;
    }

    /**
     * The panel a membership is asked of: the one the request is in. A page
     * declared in no panel has none, and every membership answer is no --
     * declare #[Panel] on it, or override this.
     */
    protected function panel(): string
    {
        return $this->panels->currentOrNull() ?? '';
    }

    /** The user's membership of the panel in the tenant in force, or null. */
    protected function membership(?Authenticatable $user): ?Membership
    {
        $tenant = $this->orm->tenant();
        $panel = $this->panel();
        if ($user === null || $tenant === '' || $panel === '') {
            return null;
        }

        return $this->memberships->membership($user, $panel, $tenant);
    }
}
