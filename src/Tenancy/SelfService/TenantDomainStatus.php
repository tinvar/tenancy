<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * Where a tenant's custom domain stands, as its domains page shows it
 * (ADR 0161). Only Verified and Failing are served: they are the active
 * domains the daily re-verification checks (CustomDomains), the realm
 * lookup answers for, and Caddy is let obtain a certificate for.
 */
enum TenantDomainStatus: string
{
    /** Added, its ownership and DNS not yet proved: not served, and held by nobody. */
    case Pending = 'pending';

    /** Proved the tenant's, and pointing at the platform at its last check. */
    case Verified = 'verified';

    /**
     * Served, but its DNS could not be read at its last checks: within the
     * grace period, after which it is deactivated (CustomDomainVerification).
     */
    case Failing = 'failing';

    /**
     * No longer served: its DNS pointed elsewhere, or could not be read for
     * longer than the grace period. Back in service only once the tenant
     * proves it is theirs again.
     */
    case Deactivated = 'deactivated';

    /** Whether the platform serves it. */
    public function active(): bool
    {
        return $this === self::Verified || $this === self::Failing;
    }
}
