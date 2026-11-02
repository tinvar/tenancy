<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Http;

use Tinvar\Accounts\AccountUsers;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\Gate;
use Tinvar\Http\Request;
use Tinvar\Identity\EmailLinks;
use Tinvar\Identity\Invitations;
use Tinvar\Orm\Orm;
use Tinvar\Tenancy\SelfService\Data\InvitationPageData;
use Tinvar\Tenancy\SelfService\Requests\AcceptInvitationRequest;
use Tinvar\Tenancy\SelfService\TenantAbility;
use Tinvar\Tenancy\SelfService\TenantActivity;
use Tinvar\Tenancy\SelfService\TenantAdministration;
use Tinvar\Tenancy\SelfService\TenantChange;
use Tinvar\Tenancy\SelfService\TenantLinks;
use Tinvar\Tenancy\SelfService\TenantMembers;
use Tinvar\Tenancy\SelfService\TenantProfiles;
use Tinvar\Tenancy\SelfService\TenantRoles;
use Tinvar\Validation\ValidationException;

/**
 * The page an invite's emailed link lands on, ready-made (ADR 0155): what
 * the invite is, and the action that accepts it.
 *
 *     #[Page('/invite', view: 'tenancy/invitation', name: 'tenancy.invitation'),
 *       Domain('{tenant}.{root}'), TenantFrom(Host::class)]
 *     final class OrganizationInvitation extends AcceptInvitationPage {}
 *
 * In no panel: the person accepting is no member yet. The link is the
 * credential (`acceptTenantInvite`), and Invitations holds it to everything
 * else -- this tenant, this origin, a week, once, and an inviter who may
 * still send it. Accepting joins the account of the invited address, never
 * the account the browser happens to be signed in as, to this tenant alone,
 * in the role the invite's record names; an address with no account yet is
 * told to make one, and the invite is left open for when it has.
 */
#[Authorize(TenantAbility::AcceptInvite->value, TenantAdministration::class)]
abstract class AcceptInvitationPage extends SelfServicePage
{
    public function __construct(
        Orm $orm,
        Gate $gate,
        protected readonly Invitations $invitations,
        protected readonly TenantLinks $links,
        protected readonly TenantMembers $members,
        protected readonly TenantProfiles $profiles,
        protected readonly AccountUsers $accounts,
        protected readonly TenantActivity $activity,
        protected readonly TenantRoles $roles,
    ) {
        parent::__construct($orm, $gate);
    }

    final public function __invoke(Request $request): InvitationPageData
    {
        $tenant = $this->tenant();
        $token = $request->query[EmailLinks::PARAMETER] ?? null;
        $invitation = \is_string($token) && $token !== '' ? $this->invitations->open($token, $this->links->originFor($tenant), $tenant) : null;
        if ($invitation === null) {
            return new InvitationPageData(false, '', '', '', false);
        }

        return new InvitationPageData(
            true,
            $this->profiles->profile($tenant)->name ?? $tenant,
            $invitation->email,
            $this->roles->of($invitation->role)?->label() ?? $invitation->role,
            $this->accounts->findByEmail($invitation->email) !== null,
        );
    }

    #[Action, Authorize(TenantAbility::AcceptInvite->value, TenantAdministration::class)]
    final public function accept(AcceptInvitationRequest $input): ActionResult
    {
        $tenant = $this->tenant();
        $origin = $this->links->originFor($tenant);

        // Looked at before it is used: an address with no account yet keeps
        // its invite for once it has one.
        $open = $this->invitations->open($input->token, $origin, $tenant)
            ?? throw new ValidationException(['token' => ['This invite has expired, was revoked or was used already. Ask for a new one.']]);
        $account = $this->accounts->findByEmail($open->email)
            ?? throw new ValidationException(['token' => ["Create an account with {$open->email} first, then open this link again."]]);

        $invitation = $this->invitations->accept($input->token, $origin, $tenant)
            ?? throw new ValidationException(['token' => ['This invite has expired, was revoked or was used already. Ask for a new one.']]);
        $this->members->grant($tenant, $invitation->panel, $account->authIdentifier(), $invitation->role);
        $this->activity->record(TenantChange::InviteAccepted, $tenant, $account, ['email' => $invitation->email, 'role' => $invitation->role]);

        return ActionResult::redirect($this->landing());
    }

    /** The root-relative path an accepted invite goes on to. */
    protected function landing(): string
    {
        return '/';
    }
}
