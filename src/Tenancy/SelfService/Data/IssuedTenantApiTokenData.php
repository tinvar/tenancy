<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;

/**
 * A token just made, with its text (ADR 0193): in the answer to the action
 * that made it, for the page to show once, and nowhere else.
 */
final readonly class IssuedTenantApiTokenData extends Data
{
    public function __construct(
        public TenantApiTokenData $token,
        #[\SensitiveParameter]
        public string $plaintext,
    ) {}
}
