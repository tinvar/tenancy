<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

/** An open invite, by id: found among the tenant's own, or a 404. */
final readonly class InviteRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(64)]
        public string $invite,
    ) {}
}
