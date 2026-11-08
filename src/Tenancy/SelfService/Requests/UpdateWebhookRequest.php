<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;
use Tinvar\Validation\Rules\Pattern;

/** One of the tenant's webhook subscriptions, by id, with its URL, events and description as they are to be (ADR 0197). */
final readonly class UpdateWebhookRequest extends FormRequest
{
    /** @param list<string> $events */
    public function __construct(
        #[Pattern(WebhookRequest::ID)]
        public string $subscription,
        #[Min(1), Max(2048)]
        public string $url,
        #[Min(1), Max(64)]
        public array $events,
        #[Max(120)]
        public string $description = '',
    ) {}
}
