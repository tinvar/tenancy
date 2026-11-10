<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Carbon\CarbonImmutable;
use Tinvar\Data\Data;

/**
 * An open invite to the signed-in person (ADR 0201): which organization,
 * as what, to which of their addresses, until when -- and the id and
 * tenant that accepting or declining it names. Never its token.
 */
final readonly class PendingInvitationData extends Data
{
    /**
     * @param string $role its role's label, as the settings page shows it
     * @param string $roleValue the role's value, as the invite keeps it
     */
    public function __construct(
        public string $id,
        public string $tenant,
        public string $organization,
        public string $panel,
        public string $email,
        public string $role,
        public string $roleValue,
        public CarbonImmutable $expiresAt,
    ) {}
}
