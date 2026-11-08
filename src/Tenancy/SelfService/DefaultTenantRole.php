<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * The roles a tenant's members hold unless the application names its own:
 * an owner may do everything, an admin everything but the tenant's name and
 * slug, its API tokens and its webhooks, a member nothing on the
 * administration pages.
 */
enum DefaultTenantRole: string implements TenantRole
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => [TenantPermission::Settings->value, TenantPermission::Members->value, TenantPermission::Domains->value, TenantPermission::ApiTokens->value, TenantPermission::Webhooks->value],
            self::Admin => [TenantPermission::Members->value, TenantPermission::Domains->value],
            self::Member => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Member => 'Member',
        };
    }
}
