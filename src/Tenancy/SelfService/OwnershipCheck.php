<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/** One look at a domain's ownership record: what was found, and the platform's words for it. */
final readonly class OwnershipCheck
{
    /** @param string $reason what is missing or failed, for the tenant; '' when proved */
    public function __construct(
        public OwnershipProof $proof,
        public string $reason = '',
    ) {}
}
