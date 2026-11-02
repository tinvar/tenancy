<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Auth\Authenticatable;
use Tinvar\Identity\InvitePolicy;

/**
 * Who may invite whom, as what, by the tenant's roles (ADR 0159): a member
 * whose role carries TenantPermission::Members may invite to any role
 * granting nothing their own does not -- "an invite is how a member brings
 * someone in, never how anyone climbs above the member who asked"
 * (InvitePolicy). Asked when an invite is made, revoked and accepted.
 */
final readonly class RoleInvitePolicy implements InvitePolicy
{
    public function __construct(
        private TenantMembers $members,
        private TenantRoles $roles = new TenantRoles(),
    ) {}

    public function mayInvite(Authenticatable $inviter, string $tenant, string $panel, string $role): bool
    {
        if ($tenant === '' || $panel === '') {
            return false;
        }
        $own = $this->roles->permissionsOf($this->members->roleOf($tenant, $panel, $inviter->authIdentifier()));

        return \in_array(TenantPermission::Members->value, $own, true) && $this->roles->within($role, $own);
    }
}
