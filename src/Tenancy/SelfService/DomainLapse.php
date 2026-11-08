<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * Why a custom domain went out of service (ADR 0198): what its domains page
 * says, and what an application's mail to the tenant's admins can name.
 * Kept beside the Deactivated status, whose meaning -- no longer served,
 * back only by proving it again -- every cause shares.
 */
enum DomainLapse: string
{
    /**
     * Its DNS pointed elsewhere, or could not be read for longer than the
     * pointing grace (CustomDomainVerification).
     */
    case Pointing = 'pointing';

    /** Its ownership record was missing at every check for longer than the proof's grace. */
    case Ownership = 'ownership';

    /**
     * Another tenant proved it with a record of its own while this one's
     * was gone: the name changed hands.
     */
    case Claimed = 'claimed';
}
