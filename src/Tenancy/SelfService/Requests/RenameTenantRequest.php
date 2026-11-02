<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

/** The organization's new name. */
final readonly class RenameTenantRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(100)]
        public string $name,
    ) {}
}
