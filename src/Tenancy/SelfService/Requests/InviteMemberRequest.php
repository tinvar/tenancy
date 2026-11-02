<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Email;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

/** Who to invite, and as what: the role is held to the application's roles and to the inviter's own. */
final readonly class InviteMemberRequest extends FormRequest
{
    public function __construct(
        #[Email, Max(254)]
        public string $email,
        #[Min(1), Max(64)]
        public string $role,
    ) {}
}
