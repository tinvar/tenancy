<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Identity\Invitation;
use Tinvar\Mail\Mailable;

/**
 * The email an invite goes out as: the application's words and template,
 * as AccountMails is for the accounts kit. Given the invite as Invitations
 * recorded it, the link to accept it -- built on the tenant's own origin,
 * never a request's Host -- and the names to greet with.
 */
interface TenantInviteMails
{
    public function invite(Invitation $invitation, string $url, string $organization, string $inviter): Mailable;
}
