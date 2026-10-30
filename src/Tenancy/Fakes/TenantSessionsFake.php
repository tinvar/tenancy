<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\Fakes;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\Request;
use Tinvar\Tenancy\TenantSessions;

/**
 * The application's half of a switch, for a test of the real TenantSwitcher
 * -- its memberships and realms the test's own -- with no session store:
 * each switch is recorded, and answered with a new id, as TenantSessions
 * owes.
 *
 * Refused in a process that says production (Guard).
 */
final class TenantSessionsFake implements TenantSessions
{
    /** @var list<array{user: string, tenant: string, session: string}> */
    private array $started = [];

    public function __construct()
    {
        Guard::refuseInProduction(self::class);
    }

    public function switchTo(Request $request, Authenticatable $user, string $tenant): string
    {
        $session = bin2hex(random_bytes(16));
        $this->started[] = ['user' => $user->authIdentifier(), 'tenant' => $tenant, 'session' => $session];

        return $session;
    }

    /** @return list<array{user: string, tenant: string, session: string}> every session started, oldest first */
    public function started(): array
    {
        return $this->started;
    }
}
