<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * The permissions a membership carries (Membership::$permissions) that
 * TenantAdministrationPolicy's defaults read: a member who holds one may
 * change that part of their tenant. A role grants them -- DefaultTenantRole,
 * or the application's own TenantRole enum.
 */
enum TenantPermission: string
{
    /** Add, check and remove the tenant's custom domains. */
    case Domains = 'tenant.domains';

    /** Rename the tenant and change its slug. */
    case Settings = 'tenant.settings';

    /** Invite, re-role and remove the tenant's members. */
    case Members = 'tenant.members';
}
