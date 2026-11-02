<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

/** The domain a tenant admin adds, as typed: TenantDomainClaims reads it as a host, or refuses it. */
final readonly class AddDomainRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(253)]
        public string $host,
    ) {}
}
