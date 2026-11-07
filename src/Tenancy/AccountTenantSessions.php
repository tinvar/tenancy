<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Accounts\SessionState;
use Tinvar\Accounts\Sessions;
use Tinvar\Auth\Authenticatable;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;

/**
 * The accounts kit's sessions, as one switches tenant on a host that
 * serves many (ADR 0181): what TenantSwitcher hands the switch to where a
 * tenant has no host of its own.
 *
 *     $switcher = new TenantSwitcher(
 *         $orm, $auth, $memberships,
 *         new AccountTenantSessions($accounts->sessions),
 *         $accounts->sessionId(...),
 *     );
 *
 * The switch is Sessions::switchTenant(): a new id, the same realm, set on
 * the response by StartSession, no epoch moved -- what TenantSessions asks
 * of every application. StartSession runs each request after it as the
 * tenant chosen, and a panel's membership check asks in that tenant every
 * time, so a member removed since is refused there.
 */
final readonly class AccountTenantSessions implements TenantSessions
{
    public function __construct(private Sessions $sessions) {}

    /**
     * @throws HttpException 401 when the session has ended since the request began,
     *         403 when it is not signed in as $user
     */
    public function switchTo(Request $request, Authenticatable $user, string $tenant): string
    {
        $state = SessionState::of($request);
        $signedIn = $state->user();
        // The user TenantSwitcher checked the membership of is the session's
        // own, or nobody's switch is made: one signed in for this request
        // some other way -- an API token -- has no session to switch.
        if ($signedIn === null || $signedIn->authIdentifier() !== $user->authIdentifier()) {
            throw new HttpException(403, 'The tenant switch is refused: this request\'s session is not signed in as the user switching.', 'tenant_switch_refused');
        }

        return $this->sessions->switchTenant($state, $signedIn, $tenant)
            ?? throw new HttpException(401, 'The session ended before its tenant could be switched.', 'unauthenticated');
    }
}
