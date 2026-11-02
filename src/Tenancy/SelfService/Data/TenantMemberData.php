<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;

/** One member on the settings page: who they are, their role, and whether it is the reader. */
final readonly class TenantMemberData extends Data
{
    public function __construct(
        public string $userId,
        public string $name,
        public string $email,
        public string $role,
        public bool $you,
    ) {}
}
