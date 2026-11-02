<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;

/**
 * The page an invite lands on: whether it can be accepted, and -- when it
 * can -- the organization, the address and the role, and whether that
 * address has an account to join yet. Never the token, which the view
 * posts back from the page's own address.
 */
final readonly class InvitationPageData extends Data
{
    public function __construct(
        public bool $open,
        public string $organization,
        public string $email,
        public string $role,
        public bool $accountExists,
    ) {}
}
