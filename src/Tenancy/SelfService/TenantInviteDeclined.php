<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Carbon\CarbonImmutable;

/**
 * Someone signed in as an invite's address declined it (ADR 0201).
 * Dispatched through the application's Events as the invite's tenant,
 * inside the transaction that declined it, so an `afterCommit` listener
 * hears only a decline that stood: the hook an application turns into a
 * note to whoever sent it.
 *
 *     #[Listener(afterCommit: true)]
 *     public function tell(TenantInviteDeclined $declined): void { ... }
 *
 * Plain values only: the invite's id, never its token.
 */
final readonly class TenantInviteDeclined
{
    /**
     * @param string $invite the invite's id
     * @param string $user the id of the account that declined it
     * @param string $inviter the id of the account that sent it
     */
    public function __construct(
        public string $tenant,
        public string $invite,
        public string $panel,
        public string $email,
        public string $role,
        public string $user,
        public string $inviter,
        public CarbonImmutable $at,
    ) {}
}
