<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

/** A member, by user id, and the role to give them: both held to the tenant's own and to the changer's role. */
final readonly class ChangeRoleRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(191)]
        public string $member,
        #[Min(1), Max(64)]
        public string $role,
    ) {}
}
