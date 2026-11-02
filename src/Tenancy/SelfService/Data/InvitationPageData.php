<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;

/**
 * The page an invite lands on: whether it can be accepted, and -- when it
 * can -- the organization, the address and the role, whether that address
 * has an account yet, whether the reader is signed in as it, and the token
 * its link carried, for the form to post back -- the address bar holds it
 * already. '' for an invite that cannot be accepted.
 */
final readonly class InvitationPageData extends Data
{
    public function __construct(
        public bool $open,
        public string $organization,
        public string $email,
        public string $role,
        public bool $accountExists,
        public bool $signedInAsInvitee,
        public string $acceptAction,
        public string $token,
    ) {}
}
