<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;

/**
 * A subscription just made, or its secret just rotated, with the secret
 * (ADR 0197): in the answer to the action, for the page to show once, and
 * nowhere else.
 */
final readonly class IssuedWebhookSecretData extends Data
{
    public function __construct(
        public TenantWebhookData $subscription,
        #[\SensitiveParameter]
        public string $secret,
    ) {}
}
