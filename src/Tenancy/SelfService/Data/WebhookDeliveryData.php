<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Carbon\CarbonImmutable;
use Tinvar\Data\Data;

/**
 * One delivery to a subscription, as its log on the page shows it (ADR
 * 0197): its latest try's outcome, status, reason code and timing, and how
 * many tries it has had -- never a body or a header the receiver sent back
 * (ADR 0038) -- and, for a resend, the delivery it repeats and who sent it.
 */
final readonly class WebhookDeliveryData extends Data
{
    public function __construct(
        public string $id,
        public string $event,
        public string $outcome,
        public ?int $status,
        public ?string $reason,
        public int $attempts,
        public int $milliseconds,
        public CarbonImmutable $attemptedAt,
        /** Kept as it was sent, so it may be sent again. */
        public bool $resendable,
        public ?string $resendOf,
        public ?string $resentBy,
    ) {}
}
