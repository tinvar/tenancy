<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/** A tenant as its settings page shows it: its id, its name, and the slug its subdomain is. */
final readonly class TenantProfile
{
    public function __construct(
        public string $tenant,
        public string $name,
        public string $slug,
    ) {}
}
