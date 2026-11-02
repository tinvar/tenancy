<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

/** The tenant to switch to, by id: checked against the switcher's own list, never trusted. */
final readonly class SwitchTenantRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(191)]
        public string $tenant,
    ) {}
}
