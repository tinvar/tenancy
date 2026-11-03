<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\HttpException;
use Tinvar\Identity\Invitation;
use Tinvar\Identity\Invitations;
use Tinvar\Identity\InvitePolicy;
use Tinvar\Validation\ValidationException;

/**
 * A tenant's settings, as its settings page reads and changes them (ADR
 * 0161): its name and slug, its members and their roles, and its invites
 * (the framework's Invitations).
 *
 * Every change is made for the tenant the request runs as, and recorded
 * (TenantActivity) once made. The page's #[Authorize] has let the member
 * in; the rules here are the ones a policy cannot see, held for every
 * caller:
 *
 * - **No one climbs.** A member may give a role -- by an invite or a change
 *   -- only when it grants nothing their own role does not, and may change
 *   or remove only a member whose role grants nothing theirs does not.
 * - **No tenant is locked out.** A change or a removal that would leave no
 *   member holding a permission some member held before -- to manage
 *   members, settings or domains -- is refused, the member's own included:
 *   no one climbs, so a permission nobody holds is never given again (an
 *   only owner stepping down to admin left no one able to rename the
 *   tenant or change its slug).
 * - **A slug** is held to SlugRules -- `admin`, `www`, the platform's own
 *   hosts -- and to being nobody else's, by the store.
 *
 * Each refusal is a ValidationException under the field it is about, with
 * what to do; a member, an invite or a role that is not this tenant's is a
 * 404, the same whether it is another tenant's or nobody's.
 */
final readonly class TenantSettings
{
    public const int MAX_NAME = 100;

    public function __construct(
        private TenantProfiles $profiles,
        private TenantMembers $members,
        private Invitations $invitations,
        private InvitePolicy $invitePolicy,
        private TenantInvites $invites,
        private SlugRules $slugs = new SlugRules(),
        private TenantRoles $roles = new TenantRoles(),
        private TenantActivity $activity = new UnrecordedTenantActivity(),
    ) {}

    public function roles(): TenantRoles
    {
        return $this->roles;
    }

    /** @throws HttpException 404 for a tenant there is no profile of */
    public function profile(string $tenant): TenantProfile
    {
        return $this->profiles->profile($tenant) ?? throw self::notFound('organization');
    }

    /** @return list<TenantMember> */
    public function members(string $tenant, string $panel): array
    {
        return $this->members->members($tenant, $panel);
    }

    /** @return list<Invitation> */
    public function pendingInvites(string $tenant, string $panel): array
    {
        return $this->invitations->pending($tenant, $panel);
    }

    /** @throws ValidationException under `name` */
    public function rename(string $tenant, string $name, ?Authenticatable $actor): void
    {
        $before = $this->profile($tenant);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME || preg_match('/[\p{Cc}\p{Cf}]/u', $name) === 1) {
            throw new ValidationException(['name' => ['Give your organization a name of 1 to ' . self::MAX_NAME . ' characters.']]);
        }
        if ($name === $before->name) {
            return;
        }

        $this->profiles->rename($tenant, $name);
        $this->activity->record(TenantChange::Renamed, $tenant, $actor, ['from' => $before->name, 'to' => $name]);
    }

    /** @throws ValidationException under `slug` */
    public function changeSlug(string $tenant, string $slug, ?Authenticatable $actor): void
    {
        $before = $this->profile($tenant);
        $slug = strtolower(trim($slug));
        if ($slug === $before->slug) {
            return;
        }
        $refusal = $this->slugs->refusal($slug);
        if ($refusal === null) {
            // Defence in depth, for a TenantProfiles of the application's own:
            // a slug some tenant holds is refused before the write. It can only
            // refuse too much -- a slug let go a moment ago -- never let one
            // through: the store's unique index, below, is what decides.
            $holder = $this->profiles->tenantForSlug($slug);
            $refusal = $holder !== null && $holder !== $tenant ? (new SlugTaken($slug))->getMessage() : null;
        }
        if ($refusal !== null) {
            throw new ValidationException(['slug' => [$refusal]]);
        }

        // Taken, finally, is the store's to say, by its unique index: the read
        // above races another tenant taking the slug at the same moment.
        try {
            $this->profiles->changeSlug($tenant, $slug);
        } catch (SlugTaken $e) {
            throw new ValidationException(['slug' => [$e->getMessage()]]);
        }
        $this->activity->record(TenantChange::SlugChanged, $tenant, $actor, ['from' => $before->slug, 'to' => $slug]);
    }

    /**
     * Invites $email to the tenant's $panel as $role: sent off the request.
     *
     * @throws ValidationException under `email` or `role`
     */
    public function invite(string $tenant, string $panel, string $email, string $role, Authenticatable $actor): void
    {
        $address = Invitations::address($email) ?? throw new ValidationException(['email' => ['Enter the email address to invite.']]);
        if ($this->roles->of($role) === null) {
            throw new ValidationException(['role' => ['Choose one of the roles listed.']]);
        }
        if (!$this->invitePolicy->mayInvite($actor, $tenant, $panel, $role)) {
            throw new ValidationException(['role' => ['You may not invite anyone to that role: choose one that grants no more than yours.']]);
        }

        $this->invites->send($actor, $address, $tenant, $panel, $role);
        $this->activity->record(TenantChange::MemberInvited, $tenant, $actor, ['email' => $address, 'role' => $role]);
    }

    /** @throws HttpException 404 for an invite that is not open in this tenant; 403 for one to a role $actor may not give */
    public function revokeInvite(string $tenant, string $panel, string $id, Authenticatable $actor): void
    {
        if (!$this->invitations->revoke($actor, $id, $tenant, $panel)) {
            throw self::notFound('invite');
        }
        $this->activity->record(TenantChange::InviteRevoked, $tenant, $actor, ['invite' => $id]);
    }

    /** @throws ValidationException under `role`; HttpException 404 for no member of this tenant */
    public function changeRole(string $tenant, string $panel, string $userId, string $role, Authenticatable $actor): void
    {
        $current = $this->members->roleOf($tenant, $panel, $userId) ?? throw self::notFound('member');
        if ($this->roles->of($role) === null) {
            throw new ValidationException(['role' => ['Choose one of the roles listed.']]);
        }
        if ($role === $current) {
            return;
        }
        $this->assertOutranks($tenant, $panel, $actor, $current, $role);
        $this->assertNotLockedOut($tenant, $panel, $userId, $role);

        $this->members->changeRole($tenant, $panel, $userId, $role);
        $this->activity->record(TenantChange::RoleChanged, $tenant, $actor, ['member' => $userId, 'from' => $current, 'to' => $role]);
    }

    /** @throws ValidationException under `member`; HttpException 404 for no member of this tenant */
    public function removeMember(string $tenant, string $panel, string $userId, Authenticatable $actor): void
    {
        $current = $this->members->roleOf($tenant, $panel, $userId) ?? throw self::notFound('member');
        $this->assertOutranks($tenant, $panel, $actor, $current, null);
        $this->assertNotLockedOut($tenant, $panel, $userId, null);

        $this->members->remove($tenant, $panel, $userId);
        $this->activity->record(TenantChange::MemberRemoved, $tenant, $actor, ['member' => $userId, 'role' => $current]);
    }

    /** Refuses $actor changing a member in $current, to $next, where either grants what the actor's role does not. */
    private function assertOutranks(string $tenant, string $panel, Authenticatable $actor, string $current, ?string $next): void
    {
        $own = $this->roles->permissionsOf($this->members->roleOf($tenant, $panel, $actor->authIdentifier()));
        if (!$this->roles->within($current, $own) && $this->roles->of($current) !== null) {
            throw new ValidationException(['member' => ['That member\'s role grants more than yours: ask someone whose role grants as much to change it.']]);
        }
        if ($next !== null && !$this->roles->within($next, $own)) {
            throw new ValidationException(['role' => ['You may not give that role: choose one that grants no more than yours.']]);
        }
    }

    /**
     * Refuses a change leaving no member of the panel holding a permission
     * one held before it: since no one gives a role granting more than
     * their own, a permission nobody holds is never given again.
     */
    private function assertNotLockedOut(string $tenant, string $panel, string $changed, ?string $next): void
    {
        $before = [];
        $after = [];
        foreach ($this->members->members($tenant, $panel) as $member) {
            $before = [...$before, ...$this->roles->permissionsOf($member->role)];
            $after = [...$after, ...$this->roles->permissionsOf($member->userId === $changed ? $next : $member->role)];
        }

        $lost = array_values(array_diff(array_unique($before), $after));
        if ($lost === []) {
            return;
        }

        throw new ValidationException(['member' => [\in_array(TenantPermission::Members->value, $lost, true)
            ? 'Someone must be left who can manage members: give another member that role first.'
            : 'Someone must be left whose role grants ' . implode(', ', $lost) . ': give another member that role first.']]);
    }

    private static function notFound(string $what): HttpException
    {
        return new HttpException(404, "That {$what} is not one of your organization's.", 'not_found');
    }
}
