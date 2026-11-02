<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * A tenant's members of a panel, and their roles, as the settings page
 * reads and changes them (ADR 0159). DatabaseTenantMembers keeps them in a
 * table of its own -- and answers the framework's Memberships from it; an
 * application with its own memberships implements this over them.
 *
 * Every method names its tenant and panel, and the pages hand it the tenant
 * the request runs as: a member of another tenant is not found.
 */
interface TenantMembers
{
    /** @return list<TenantMember> the tenant's members of $panel, in a stable order */
    public function members(string $tenant, string $panel): array;

    /** The member's role's value, or null for someone who is no member. */
    public function roleOf(string $tenant, string $panel, string $userId): ?string;

    /**
     * Makes $userId a member in $role. One already a member is left as they
     * are: accepting an invite never takes a role away.
     */
    public function grant(string $tenant, string $panel, string $userId, string $role): void;

    public function changeRole(string $tenant, string $panel, string $userId, string $role): void;

    public function remove(string $tenant, string $panel, string $userId): void;
}
