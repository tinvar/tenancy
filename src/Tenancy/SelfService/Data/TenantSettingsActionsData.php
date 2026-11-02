<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;

/** The settings page's actions, by the wire name each form posts to: `<Class>.<method>`. */
final readonly class TenantSettingsActionsData extends Data
{
    public function __construct(
        public string $rename,
        public string $changeSlug,
        public string $invite,
        public string $revokeInvite,
        public string $changeRole,
        public string $removeMember,
    ) {}
}
