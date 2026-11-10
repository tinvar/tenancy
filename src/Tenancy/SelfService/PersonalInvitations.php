<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Carbon\CarbonImmutable;
use Tinvar\Accounts\AccountUser;
use Tinvar\Accounts\VerifiedEmails;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\UserLookup;
use Tinvar\Events\Events;
use Tinvar\Http\HttpException;
use Tinvar\Identity\Invitation;
use Tinvar\Identity\Invitations;
use Tinvar\Orm\Orm;
use Tinvar\Orm\TenantBounds;
use Tinvar\Tenancy\SelfService\Data\PendingInvitationData;

/**
 * The invites sent to whoever is signed in, wherever they came from, and
 * accepting or declining one without the emailed link (ADR 0201): what an
 * application's tenant switcher lists beside the tenants its user is in.
 *
 *     $pending = $selfService->personalInvitations->pending('customer');
 *     $selfService->personalInvitations->accept($tenant, $id);
 *
 * Who they are for is the signed-in account's own: its verified addresses
 * -- accountEmail(), or every one VerifiedEmails lists -- read from the
 * application's users by the session's user id, never from the request.
 * Each call refuses, 401, with nobody signed in, and 403 a request signed
 * in by an API token, which is one tenant's and reads no other's invites;
 * accept() and decline() refuse, 403, under impersonation too: an invite is
 * the person's own to take or turn down.
 *
 * Accepting is the emailed link's accepting, by the invite's id: the
 * invite held to its record -- open, unexpired, to one of the account's
 * addresses, its inviter still allowed -- used once, and the account made
 * a member of its tenant's panel in its role, recorded as
 * `tenant.invite_accepted`, as AcceptInvitationPage does, running as the
 * invite's tenant and in one transaction. Declining closes it, its record
 * marked declined, recorded as `tenant.invite_declined` and dispatched as
 * TenantInviteDeclined. Anything else -- none with that id, someone else's,
 * used, revoked, expired -- answers one 404, which says nothing about whose
 * it is. The session stays in the tenant it was in: switching to the one
 * joined is the switcher's.
 */
final readonly class PersonalInvitations
{
    public function __construct(
        private Orm $orm,
        private AuthContext $auth,
        private UserLookup $users,
        private Invitations $invitations,
        private TenantMembers $members,
        private TenantProfiles $profiles,
        private TenantRoles $roles,
        private TenantActivity $activity,
        private ?Events $events = null,
    ) {}

    /**
     * The open invites to the signed-in account's verified addresses,
     * newest first; those to $panel alone when it names one.
     *
     * @return list<PendingInvitationData>
     * @throws HttpException 401 with nobody signed in, 403 by an API token
     */
    public function pending(?string $panel = null): array
    {
        $person = $this->person(false);
        $pending = [];
        foreach ($this->invitations->addressedTo(self::emailsOf($person)) as $invitation) {
            if ($panel !== null && $invitation->panel !== $panel) {
                continue;
            }
            $pending[] = new PendingInvitationData(
                $invitation->id,
                $invitation->tenant,
                $this->orm->actAs($invitation->tenant, fn (): string => $this->profiles->profile($invitation->tenant)->name ?? $invitation->tenant),
                $invitation->panel,
                $invitation->email,
                $this->roles->of($invitation->role)?->label() ?? $invitation->role,
                $invitation->role,
                CarbonImmutable::createFromTimestamp($invitation->expiresAt, 'UTC'),
            );
        }

        return $pending;
    }

    /**
     * Accepts the invite with this id in $tenant as the signed-in account,
     * and returns it: the account is then a member of its tenant's panel.
     *
     * @throws HttpException 401 with nobody signed in, 403 by an API token or under impersonation,
     *         404 for an invite not open to them
     */
    public function accept(string $tenant, string $id): Invitation
    {
        $person = $this->person(true);
        if ($tenant === '' || \strlen($tenant) > 191 || !TenantBounds::isName($tenant)) {
            throw self::notOpen();
        }

        return $this->orm->actAs($tenant, fn (): Invitation => $this->orm->transaction(function () use ($person, $tenant, $id): Invitation {
            $invitation = $this->invitations->acceptFor($id, $tenant, self::emailsOf($person)) ?? throw self::notOpen();
            $this->members->grant($tenant, $invitation->panel, $person->authIdentifier(), $invitation->role);
            $this->activity->record(TenantChange::InviteAccepted, $tenant, $person, ['email' => $invitation->email, 'role' => $invitation->role]);

            return $invitation;
        }));
    }

    /**
     * Declines the invite with this id in $tenant as the signed-in account,
     * and returns it, declinedAt set.
     *
     * @throws HttpException 401 with nobody signed in, 403 by an API token or under impersonation,
     *         404 for an invite not open to them
     */
    public function decline(string $tenant, string $id): Invitation
    {
        $person = $this->person(true);
        if ($tenant === '' || \strlen($tenant) > 191 || !TenantBounds::isName($tenant)) {
            throw self::notOpen();
        }

        return $this->orm->actAs($tenant, fn (): Invitation => $this->orm->transaction(function () use ($person, $tenant, $id): Invitation {
            $invitation = $this->invitations->declineFor($id, $tenant, self::emailsOf($person)) ?? throw self::notOpen();
            $this->activity->record(TenantChange::InviteDeclined, $tenant, $person, ['email' => $invitation->email, 'role' => $invitation->role]);
            $this->events?->dispatch(new TenantInviteDeclined(
                $tenant,
                $invitation->id,
                $invitation->panel,
                $invitation->email,
                $invitation->role,
                $person->authIdentifier(),
                $invitation->inviter,
                CarbonImmutable::createFromTimestamp($invitation->declinedAt ?? 0, 'UTC'),
            ));

            return $invitation;
        }));
    }

    /**
     * The signed-in account, as the application's users have it now, by a
     * session -- for $acting, one of its own, nobody impersonating it.
     */
    private function person(bool $acting): AccountUser
    {
        $id = $this->auth->id();
        $person = $id === null ? null : $this->users->find($id);
        if ($id === null || !$person instanceof AccountUser || $person->authIdentifier() !== $id) {
            throw new HttpException(401, 'Sign in to see the invites sent to you.', 'unauthenticated');
        }
        if ($this->auth->apiToken() !== null || ($acting && $this->auth->impersonator() !== null)) {
            throw new HttpException(403, 'Only the person an invite was sent to may accept or decline it, signed in as themselves.', 'forbidden');
        }

        return $person;
    }

    /** @return list<string> */
    private static function emailsOf(AccountUser $person): array
    {
        return $person instanceof VerifiedEmails ? array_values(array_filter($person->verifiedEmails(), \is_string(...))) : [$person->accountEmail()];
    }

    private static function notOpen(): HttpException
    {
        return new HttpException(404, 'That invite is not open to you: it may have expired, been revoked, or been used already.', 'not_found');
    }
}
