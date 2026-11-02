<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;

/** A role a member may hold, as the settings page offers it. */
final readonly class TenantRoleData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}
}
