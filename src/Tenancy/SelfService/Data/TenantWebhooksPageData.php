<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;

/**
 * The tenant's webhooks page (ADR 0197): its subscriptions, the events a
 * subscription may choose, the one subscription whose deliveries are open
 * and those deliveries, whether the member may change and resend -- which
 * forms to draw, never what the actions allow -- and the actions the forms
 * post to, named as the application's class is.
 */
final readonly class TenantWebhooksPageData extends Data
{
    /**
     * @param list<TenantWebhookData> $subscriptions
     * @param list<string> $events
     * @param list<WebhookDeliveryData> $deliveries the open subscription's, newest first
     * @param array<string, string> $actions by method: create, update, pause, resume, delete, rotate, test, resend
     */
    public function __construct(
        public array $subscriptions,
        public array $events,
        public ?string $open,
        public array $deliveries,
        public bool $canManage,
        public bool $canResend,
        public array $actions,
    ) {}
}
