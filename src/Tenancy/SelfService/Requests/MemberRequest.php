<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

/** A member, by user id: found among the tenant's own, or a 404. */
final readonly class MemberRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(191)]
        public string $member,
    ) {}
}
