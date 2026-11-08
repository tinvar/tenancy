<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Mail\Mailer;
use Tinvar\Webhooks\StoredWebhookSubscription;
use Tinvar\Webhooks\WebhookDisabledNotice;

/**
 * What a tenant is told when the framework disables one of its webhook
 * subscriptions (ADR 0197): the change recorded with TenantActivity, made
 * by nobody -- the platform's own work -- and, where the application gives
 * a mail, an email sent to every member of the panel whose role grants
 * the webhooks permission.
 *
 *     new DatabaseWebhookSubscriptions($orm, $signer, $urls, notice: new NotifiedWebhookDisabling(
 *         $activity, panel: 'customer', mails: new WebhookDisabledEmail(), mailer: $mailer, members: $members, profiles: $tenants,
 *     ));
 *
 * Told by the delivery that disabled it, which runs as the subscription's
 * tenant: the record is filed in that tenant's log, and the members read
 * are that tenant's alone.
 */
final readonly class NotifiedWebhookDisabling implements WebhookDisabledNotice
{
    /**
     * @param string $panel the panel whose members are told
     * @param string $permission the permission a member's role grants that makes them one to tell
     */
    public function __construct(
        private TenantActivity $activity,
        private string $panel = '',
        private ?WebhookDisabledMails $mails = null,
        private ?Mailer $mailer = null,
        private ?TenantMembers $members = null,
        private TenantRoles $roles = new TenantRoles(),
        private ?TenantProfiles $profiles = null,
        private string $permission = TenantPermission::Webhooks->value,
    ) {
        if ($mails !== null && ($mailer === null || $members === null || $panel === '')) {
            throw new \InvalidArgumentException('A mail to the tenant\'s admins needs the mailer, the members and the panel they are members of.');
        }
    }

    public function disabled(StoredWebhookSubscription $subscription): void
    {
        $this->activity->record(TenantChange::WebhookDisabled, $subscription->tenant, null, [
            'id' => $subscription->id,
            'reason' => $subscription->disabledReason->value ?? '',
            'last_failure' => $subscription->lastFailure ?? '',
        ]);

        if ($this->mails === null || $this->mailer === null || $this->members === null) {
            return;
        }
        $organization = $this->profiles?->profile($subscription->tenant)->name ?? $subscription->tenant;
        foreach ($this->members->members($subscription->tenant, $this->panel) as $member) {
            if ($member->email !== '' && \in_array($this->permission, $this->roles->permissionsOf($member->role), true)) {
                $this->mailer->send($this->mails->disabled($subscription, $member, $organization));
            }
        }
    }
}
