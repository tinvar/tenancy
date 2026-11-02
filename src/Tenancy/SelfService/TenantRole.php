<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * A role a tenant's member holds, as the application names them: a backed
 * enum implementing this, each case granting permissions (TenantPermission,
 * and any of the application's own). DefaultTenantRole unless the
 * application gives its own enum to the self-service kit.
 *
 * What a role grants is the code's, never a row's: a member's role is
 * stored by its value and read back as a case, so a value no case has is
 * no role and grants nothing.
 */
interface TenantRole extends \BackedEnum
{
    /**
     * What a member in this role may do, as Membership::$permissions.
     *
     * @return list<string>
     */
    public function permissions(): array;

    /** The role's name, as the settings page shows it. */
    public function label(): string;
}
