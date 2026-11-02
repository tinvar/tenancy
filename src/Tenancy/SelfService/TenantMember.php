<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/** One member of a tenant's panel, as its settings page lists them. */
final readonly class TenantMember
{
    /** @param string $role the role's value: one of the application's TenantRole cases */
    public function __construct(
        public string $userId,
        public string $name,
        public string $email,
        public string $role,
    ) {}
}
