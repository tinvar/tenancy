<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * Where a switch sends the browser: a path on this host, under the new
 * session TenantSessions started, or -- for a tenant on a host of its own
 * -- an absolute URL, the handoff's start on that host.
 */
final readonly class TenantDestination
{
    /**
     * @param string $location a root-relative path for Current and Session, an absolute
     *        https URL (http on localhost) for Handoff
     * @param string $sessionId the new session's id, for Session alone
     */
    public function __construct(
        public TenantSwitchVia $via,
        public string $location,
        public string $sessionId = '',
    ) {}

    /** Whether the browser leaves this origin, and the action answers with location(). */
    public function offOrigin(): bool
    {
        return $this->via === TenantSwitchVia::Handoff;
    }
}
