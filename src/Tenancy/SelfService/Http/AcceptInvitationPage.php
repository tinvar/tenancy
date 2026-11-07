<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Http;

use Tinvar\Accounts\AccountUsers;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Gate;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\RequestAttributes;
use Tinvar\Identity\EmailLinks;
use Tinvar\Identity\Invitation;
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
use Tinvar\Tenancy\SelfService\TenantSwitchboard;
use Tinvar\Validation\ValidationException;

/**
 * The page an invite's emailed link lands on, ready-made (ADR 0161): what
 * the invite is, and the action that accepts it.
 *
 * Where each tenant has a host of its own, the page reads its tenant from
 * the host, and an invite opens only on its tenant's:
 *
 *     #[Page('/invite', view: 'tenancy/invitation', name: 'tenancy.invitation'),
 *       Domain('{tenant}.{root}'), TenantFrom(Host::class)]
 *     final class OrganizationInvitation extends AcceptInvitationPage {}
 *
 * Where every tenant is served on one host (ADR 0181), the page declares no
 * tenant source, and the link names its own (ADR 0182):
 *
 *     #[Page('/invite', view: 'tenancy/invitation', name: 'tenancy.invitation')]
 *     final class OrganizationInvitation extends AcceptInvitationPage {}
 *
 * There the person accepting is usually in no tenant, or in another
 * organization, so neither the host nor the session can say whose invite
 * it is. The token can: it carries the tenant it was made in under the
 * application key's MAC (Invitations::tenantOf()), and the page and its
 * action run as that tenant -- never as the one the session holds -- for
 * reading the invite and granting it alone. A token edited to name another
 * tenant fails its MAC; one made for a tenant with a host of its own
 * (TenantLinks::hasOwnHost()) is accepted on that host, not here. Which of
 * the two the page is, is the request's: placed in a tenant by a
 * #[TenantFrom] source, it holds the invite to that tenant, as before.
 *
 * In no panel: the person accepting is no member yet. Anyone holding the
 * link may open the page (`acceptTenantInvite`); Invitations holds the link
 * to everything else -- its tenant, its origin, a week, once, and an
 * inviter who may still send it. Accepting takes someone signed in as the
 * account of the invited address -- a link forwarded, or opened in a
 * browser signed in as someone else, joins nobody -- and joins that account
 * to the invite's tenant alone, in the role the invite's record names. An
 * address with no account yet is told to make one, and the invite is left
 * open for when it has.
 *
 * On one host, once the grant commits, the session is switched into the
 * tenant joined, through the kit's TenantSwitchboard -- a new session id,
 * the membership checked again -- where the application gave the kit a
 * session switcher; without one, or where the switch is refused, the
 * session stays as it was and the invite is accepted all the same.
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
        protected readonly AuthContext $auth,
        protected readonly TenantSwitchboard $switchboard,
    ) {
        parent::__construct($orm, $gate);
    }

    final public function __invoke(Request $request): InvitationPageData
    {
        $token = $request->query[EmailLinks::PARAMETER] ?? null;
        $token = \is_string($token) ? $token : '';
        $closed = new InvitationPageData(false, '', '', '', false, false, $this->action('accept'), '');
        $tenant = $this->invitationTenant($request, $token);
        if ($tenant === null || $token === '') {
            return $closed;
        }

        return $this->orm->actAs($tenant, function () use ($tenant, $token, $closed): InvitationPageData {
            $invitation = $this->invitations->open($token, $this->links->originFor($tenant), $tenant);
            if ($invitation === null) {
                return $closed;
            }
            $account = $this->accounts->findByEmail($invitation->email);

            return new InvitationPageData(
                true,
                $this->profiles->profile($tenant)->name ?? $tenant,
                $invitation->email,
                $this->roles->of($invitation->role)?->label() ?? $invitation->role,
                $account !== null,
                $account !== null && $account->authIdentifier() === $this->auth->id(),
                $this->action('accept'),
                $token,
            );
        });
    }

    #[Action(transaction: true), Authorize(TenantAbility::AcceptInvite->value, TenantAdministration::class)]
    final public function accept(AcceptInvitationRequest $input, Request $request): ActionResult
    {
        $tenant = $this->invitationTenant($request, $input->token) ?? throw self::closed();
        $invitation = $this->orm->actAs($tenant, fn (): Invitation => $this->join($input->token, $tenant));

        // On one host, into the tenant joined -- once the grant commits, as
        // the switch is a durable write of its own.
        if (!self::placed($request)) {
            $this->orm->afterCommit(fn () => $this->enter($request, $invitation));
        }

        return ActionResult::redirect($this->landing());
    }

    /** The root-relative path an accepted invite goes on to. */
    protected function landing(): string
    {
        return '/';
    }

    /** Uses the invite in $tenant, running as it, and grants it to the signed-in account of its address. */
    private function join(string $token, string $tenant): Invitation
    {
        $origin = $this->links->originFor($tenant);

        // Looked at before it is used: an address with no account yet keeps
        // its invite for once it has one.
        $open = $this->invitations->open($token, $origin, $tenant) ?? throw self::closed();
        $account = $this->accounts->findByEmail($open->email)
            ?? throw new ValidationException(['token' => ["Create an account with {$open->email} first, then open this link again."]]);
        if ($account->authIdentifier() !== $this->auth->id()) {
            throw new ValidationException(['token' => ["This invite is for {$open->email}: sign in as that account, then accept it."]]);
        }

        $invitation = $this->invitations->accept($token, $origin, $tenant) ?? throw self::closed();
        $this->members->grant($tenant, $invitation->panel, $account->authIdentifier(), $invitation->role);
        $this->activity->record(TenantChange::InviteAccepted, $tenant, $account, ['email' => $invitation->email, 'role' => $invitation->role]);

        return $invitation;
    }

    /**
     * The tenant the invite is read and accepted in, or null for none: the
     * request's, where a #[TenantFrom] source placed it -- the host's -- and
     * the token's own otherwise, held to a tenant served on this shared
     * origin. Never the tenant a session merely holds.
     */
    private function invitationTenant(Request $request, string $token): ?string
    {
        if (self::placed($request)) {
            return $this->tenant();
        }
        $tenant = $token === '' ? null : $this->invitations->tenantOf($token);

        return $tenant !== null && $tenant !== '' && !$this->links->hasOwnHost($tenant) ? $tenant : null;
    }

    /** Whether a #[TenantFrom] source placed the request in its tenant (EnterTenant). */
    private static function placed(Request $request): bool
    {
        return $request->attribute(RequestAttributes::TENANT_SOURCE) !== null;
    }

    /**
     * Switches the session into the tenant just joined, where the kit can
     * on this host; nothing where it already runs as it, or the switch is
     * refused -- the invite is accepted either way.
     */
    private function enter(Request $request, Invitation $invitation): void
    {
        if ($this->orm->tenant() === $invitation->tenant) {
            return;
        }

        try {
            $this->switchboard->switch($request, $invitation->tenant, $invitation->panel, $this->landing());
        } catch (HttpException) {
            // No switcher on this host, a membership the panel does not see,
            // or a session ended meanwhile: the person picks the tenant from
            // the switcher instead.
        }
    }

    private static function closed(): ValidationException
    {
        return new ValidationException(['token' => ['This invite has expired, was revoked or was used already. Ask for a new one.']]);
    }
}
