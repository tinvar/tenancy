<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Auth\Authenticatable;

/**
 * Where the tenant self-service pages record what they changed (ADR 0155):
 * the seam an activity log plugs into.
 *
 * Told of every change once it is made, inside the transaction that made
 * it -- so a record that cannot be written takes the change back with it,
 * and no change goes unrecorded where recording is required. The details
 * are what changed, in plain values: an old and a new name, a host, a role,
 * an invited address. Never a token, a password or a session id.
 *
 * Unless the application gives one, UnrecordedTenantActivity: nothing is
 * kept. An application with an activity log gives the self-service kit an
 * implementation writing to it, scoped to the tenant it is told of.
 */
interface TenantActivity
{
    /**
     * @param string $tenant the tenant changed
     * @param Authenticatable|null $actor who changed it; null for the platform's own work
     * @param array<string, string> $details
     */
    public function record(TenantChange $change, string $tenant, ?Authenticatable $actor, array $details = []): void;
}
