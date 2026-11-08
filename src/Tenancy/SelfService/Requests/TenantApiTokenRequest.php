<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Pattern;

/** One of the tenant's API tokens, by its id: one of another tenant's is none. */
final readonly class TenantApiTokenRequest extends FormRequest
{
    public function __construct(
        #[Pattern('[0-9a-f]{32}')]
        public string $token,
    ) {}
}
