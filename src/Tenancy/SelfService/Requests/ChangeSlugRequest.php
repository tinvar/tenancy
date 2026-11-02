<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

/** The organization's new slug: its subdomain's label, held to SlugRules. */
final readonly class ChangeSlugRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(63)]
        public string $slug,
    ) {}
}
