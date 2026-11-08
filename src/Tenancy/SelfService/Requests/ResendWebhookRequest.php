<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Pattern;

/**
 * One delivery to one of the tenant's subscriptions, to be sent again
 * (ADR 0197): a delivery of another subscription, or another tenant's, is
 * none.
 */
final readonly class ResendWebhookRequest extends FormRequest
{
    public function __construct(
        #[Pattern(WebhookRequest::ID)]
        public string $subscription,
        #[Pattern(WebhookRequest::ID)]
        public string $delivery,
    ) {}
}
