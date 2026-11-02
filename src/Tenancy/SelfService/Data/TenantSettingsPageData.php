<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;

/**
 * The settings page: the organization's name and slug, its members and open
 * invites, the roles there are, which forms to draw for the reader --
 * never what the actions allow, which ask again -- and the actions the
 * forms post to.
 */
final readonly class TenantSettingsPageData extends Data
{
    /**
     * @param list<TenantMemberData> $members
     * @param list<TenantInviteData> $invites
     * @param list<TenantRoleData> $roles
     */
    public function __construct(
        public string $name,
        public string $slug,
        public array $members,
        public array $invites,
        public array $roles,
        public bool $canManageSettings,
        public bool $canManageMembers,
        public TenantSettingsActionsData $actions,
    ) {}
}
