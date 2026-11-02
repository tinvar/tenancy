<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;

/**
 * The page an invite lands on: whether it can be accepted, and -- when it
 * can -- the organization, the address and the role, whether that address
 * has an account yet, and whether the reader is signed in as it. Never the
 * token, which the view posts back from the page's own address.
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
    ) {}
}
