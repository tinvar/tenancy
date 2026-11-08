<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

/**
 * A new webhook subscription for the tenant (ADR 0197): where it goes, the
 * events it is sent, and what it is for. Which events there are is the
 * application's to say, and whether the URL will do OutboundHttp's, so the
 * page and the store hold each and say which will not.
 */
final readonly class CreateWebhookRequest extends FormRequest
{
    /** @param list<string> $events */
    public function __construct(
        #[Min(1), Max(2048)]
        public string $url,
        #[Min(1), Max(64)]
        public array $events,
        #[Max(120)]
        public string $description = '',
    ) {}
}
