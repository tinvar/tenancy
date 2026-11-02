<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Auth\Authenticatable;

/**
 * Sending an invite from the settings page (ADR 0155): made with the
 * framework's Invitations and emailed, off the request.
 *
 * QueuedTenantInvites dispatches SendTenantInvite, which makes the link on
 * the worker -- so it is never a row in the jobs table -- and mails what the
 * application's TenantInviteMails writes. TenantSettings has already asked
 * the InvitePolicy; Invitations asks it again on the worker, of the inviter
 * as they are then.
 */
interface TenantInvites
{
    public function send(Authenticatable $inviter, string $email, string $tenant, string $panel, string $role): void;
}
