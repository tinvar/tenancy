<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Carbon\CarbonImmutable;
use Tinvar\Data\Data;
use Tinvar\Webhooks\StoredWebhookSubscription;
use Tinvar\Webhooks\WebhookSubscriptionStatus;

/**
 * One of the tenant's webhook subscriptions as its page lists it (ADR
 * 0197): where it stands -- and, disabled, why -- but never its secret,
 * which nothing keeps.
 */
final readonly class TenantWebhookData extends Data
{
    /** @param list<string> $events */
    public function __construct(
        public string $id,
        public string $url,
        public string $description,
        public array $events,
        public WebhookSubscriptionStatus $status,
        public string $statusLabel,
        public int $consecutiveFailures,
        public ?string $lastFailure,
        public ?string $disabledReason,
        public ?CarbonImmutable $disabledAt,
        /** Until when the secret it replaced still signs; null when only one does. */
        public ?CarbonImmutable $previousSecretUntil,
        public string $createdBy,
        public CarbonImmutable $createdAt,
    ) {}

    public static function of(StoredWebhookSubscription $subscription, CarbonImmutable $now): self
    {
        return new self(
            id: $subscription->id,
            url: $subscription->url,
            description: $subscription->description,
            events: $subscription->events,
            status: $subscription->status,
            statusLabel: $subscription->status->describe(),
            consecutiveFailures: $subscription->consecutiveFailures,
            lastFailure: $subscription->lastFailure,
            disabledReason: $subscription->disabledReason?->describe(),
            disabledAt: $subscription->disabledAt,
            previousSecretUntil: $subscription->overlapping($now) ? $subscription->previousSecretUntil : null,
            createdBy: $subscription->createdBy,
            createdAt: $subscription->createdAt,
        );
    }
}
