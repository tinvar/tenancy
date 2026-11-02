<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Http;

use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Gate;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Identity\HandoffEndpoint;
use Tinvar\Identity\Invitation;
use Tinvar\Orm\Orm;
use Tinvar\Routing\HostMatch;
use Tinvar\Tenancy\SelfService\Data\TenantInviteData;
use Tinvar\Tenancy\SelfService\Data\TenantMemberData;
use Tinvar\Tenancy\SelfService\Data\TenantRoleData;
use Tinvar\Tenancy\SelfService\Data\TenantSettingsPageData;
use Tinvar\Tenancy\SelfService\Requests\ChangeRoleRequest;
use Tinvar\Tenancy\SelfService\Requests\ChangeSlugRequest;
use Tinvar\Tenancy\SelfService\Requests\InviteMemberRequest;
use Tinvar\Tenancy\SelfService\Requests\InviteRequest;
use Tinvar\Tenancy\SelfService\Requests\MemberRequest;
use Tinvar\Tenancy\SelfService\Requests\RenameTenantRequest;
use Tinvar\Tenancy\SelfService\TenantAbility;
use Tinvar\Tenancy\SelfService\TenantAdministration;
use Tinvar\Tenancy\SelfService\TenantLinks;
use Tinvar\Tenancy\SelfService\TenantMember;
use Tinvar\Tenancy\SelfService\TenantRole;
use Tinvar\Tenancy\SelfService\TenantSettings;

/**
 * A tenant's settings, ready-made (ADR 0155): its name, its slug, its
 * members and their roles, and its invites.
 *
 *     #[Page('/settings', view: 'tenancy/settings', name: 'tenancy.settings'),
 *       Domain('{tenant}.{root}'), TenantFrom(Host::class), Panel('customer')]
 *     final class OrganizationSettings extends TenantSettingsPage {}
 *
 * Seeing it is `viewTenantSettings`; renaming and the slug are
 * `manageTenantSettings`; inviting, revoking an invite, changing a role and
 * removing a member are `manageTenantMembers` -- each on its final action,
 * `<Class>.rename`, `<Class>.changeSlug`, `<Class>.invite`,
 * `<Class>.revokeInvite`, `<Class>.changeRole` and `<Class>.removeMember`.
 * TenantSettings holds the rules a policy cannot see: no one gives a role
 * above their own, no change leaves nobody able to manage members, a slug
 * keeps off the platform's names.
 *
 * A new slug is a new subdomain: once it is changed, the browser is sent to
 * the tenant's new address through the handoff, which signs it in there,
 * when the application says where tenants are served (TenantAddresses).
 */
#[Authorize(TenantAbility::ViewSettings->value, TenantAdministration::class)]
abstract class TenantSettingsPage extends SelfServicePage
{
    public function __construct(
        Orm $orm,
        Gate $gate,
        protected readonly TenantSettings $settings,
        protected readonly AuthContext $auth,
        protected readonly PanelContext $panels,
        protected readonly TenantLinks $links,
    ) {
        parent::__construct($orm, $gate);
    }

    final public function __invoke(): TenantSettingsPageData
    {
        $tenant = $this->tenant();
        $panel = $this->panel();
        $profile = $this->settings->profile($tenant);
        $you = $this->auth->id();

        return new TenantSettingsPageData(
            name: $profile->name,
            slug: $profile->slug,
            members: array_map(
                static fn (TenantMember $m): TenantMemberData => new TenantMemberData($m->userId, $m->name, $m->email, $m->role, $m->userId === $you),
                $this->settings->members($tenant, $panel),
            ),
            invites: array_map(
                static fn (Invitation $i): TenantInviteData => new TenantInviteData($i->id, $i->email, $i->role, \Carbon\CarbonImmutable::createFromTimestamp($i->expiresAt, 'UTC')),
                $this->settings->pendingInvites($tenant, $panel),
            ),
            roles: array_map(
                static fn (TenantRole $r): TenantRoleData => new TenantRoleData((string) $r->value, $r->label()),
                $this->settings->roles()->all(),
            ),
            canManageSettings: $this->may(TenantAbility::ManageSettings),
            canManageMembers: $this->may(TenantAbility::ManageMembers),
        );
    }

    #[Action, Authorize(TenantAbility::ManageSettings->value, TenantAdministration::class)]
    final public function rename(RenameTenantRequest $input): ActionResult
    {
        $this->settings->rename($this->tenant(), $input->name, $this->actor());

        return $this->again();
    }

    #[Action, Authorize(TenantAbility::ManageSettings->value, TenantAdministration::class)]
    final public function changeSlug(ChangeSlugRequest $input, Request $request): ActionResult
    {
        $tenant = $this->tenant();
        $this->settings->changeSlug($tenant, $input->slug, $this->actor());

        // The tenant's address may have moved with its slug: its new host,
        // signed in to by the handoff, with this page's path to return to.
        $origin = $this->links->originFor($tenant);
        $host = (string) parse_url($origin, PHP_URL_HOST);
        if ($host !== '' && $host !== HostMatch::hostOf($request)) {
            return ActionResult::location($origin . HandoffEndpoint::START . '?' . http_build_query(['return' => $this->path()], '', '&', PHP_QUERY_RFC3986));
        }

        return $this->again();
    }

    #[Action, Authorize(TenantAbility::ManageMembers->value, TenantAdministration::class)]
    final public function invite(InviteMemberRequest $input): ActionResult
    {
        $this->settings->invite($this->tenant(), $this->panel(), $input->email, $input->role, $this->actor());

        return $this->again(['invited' => strtolower(trim($input->email))]);
    }

    #[Action, Authorize(TenantAbility::ManageMembers->value, TenantAdministration::class)]
    final public function revokeInvite(InviteRequest $input): ActionResult
    {
        $this->settings->revokeInvite($this->tenant(), $this->panel(), $input->invite, $this->actor());

        return $this->again();
    }

    #[Action, Authorize(TenantAbility::ManageMembers->value, TenantAdministration::class)]
    final public function changeRole(ChangeRoleRequest $input): ActionResult
    {
        $this->settings->changeRole($this->tenant(), $this->panel(), $input->member, $input->role, $this->actor());

        return $this->again();
    }

    #[Action, Authorize(TenantAbility::ManageMembers->value, TenantAdministration::class)]
    final public function removeMember(MemberRequest $input): ActionResult
    {
        $this->settings->removeMember($this->tenant(), $this->panel(), $input->member, $this->actor());

        return $this->again();
    }

    /** The panel whose members and invites the page keeps: the one it is declared in. */
    protected function panel(): string
    {
        return $this->panels->currentOrNull() ?? throw new \LogicException(
            static::class . ' keeps the members of a panel: declare #[Panel(...)] on it, or override panel().',
        );
    }

    private function actor(): Authenticatable
    {
        return $this->auth->user() ?? throw new HttpException(401, 'Sign in to change your organization\'s settings.', 'unauthenticated');
    }
}
