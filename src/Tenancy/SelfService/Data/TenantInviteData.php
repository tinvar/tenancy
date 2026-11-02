<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Carbon\CarbonImmutable;
use Tinvar\Data\Data;

/** An open invite on the settings page: never its token, which only its email holds. */
final readonly class TenantInviteData extends Data
{
    public function __construct(
        public string $id,
        public string $email,
        public string $role,
        public CarbonImmutable $expiresAt,
    ) {}
}
