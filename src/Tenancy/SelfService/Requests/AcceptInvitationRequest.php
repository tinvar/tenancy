<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

/** The invite's token, as its emailed link carried it. */
final readonly class AcceptInvitationRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(4096)]
        public string $token,
    ) {}
}
