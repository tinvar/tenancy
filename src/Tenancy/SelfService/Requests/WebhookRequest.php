<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Pattern;

/** One of the tenant's webhook subscriptions, by its id: one of another tenant's is none (ADR 0197). */
final readonly class WebhookRequest extends FormRequest
{
    /** A subscription's id: a UUID, as DatabaseWebhookSubscriptions makes one. */
    public const string ID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        #[Pattern(self::ID)]
        public string $subscription,
    ) {}
}
