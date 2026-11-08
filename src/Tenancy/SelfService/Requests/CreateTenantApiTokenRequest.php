<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Api\ApiToken;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

/**
 * A new token for the tenant (ADR 0193): what it is for, its scopes, and
 * how many days it lasts. Which scopes there are is the API's to say
 * (TenantApiTokens::grantable()), and how long a token may last the
 * application's, so TenantApiTokens holds each and says which will not do.
 */
final readonly class CreateTenantApiTokenRequest extends FormRequest
{
    /** @param list<string> $scopes */
    public function __construct(
        #[Min(1), Max(ApiToken::MAX_NAME_LENGTH)]
        public string $name,
        #[Min(1), Max(16)]
        public array $scopes,
        #[Min(1), Max(3650)]
        public int $days = 90,
    ) {}
}
