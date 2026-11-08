<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Mail\Mailable;
use Tinvar\Webhooks\StoredWebhookSubscription;

/**
 * The email a tenant's admins are sent when the framework disables one of
 * its webhook subscriptions (ADR 0197): the application's words and
 * template, as TenantInviteMails is for an invite. Given the subscription
 * -- its reason and last failure beside it, never its secret -- the member
 * to send it to, and the organization's name.
 */
interface WebhookDisabledMails
{
    public function disabled(StoredWebhookSubscription $subscription, TenantMember $to, string $organization): Mailable;
}
