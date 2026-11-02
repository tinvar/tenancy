<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Accounts\AccountUser;
use Tinvar\Attributes\Queue;
use Tinvar\Auth\UserLookup;
use Tinvar\Http\HttpException;
use Tinvar\Identity\Invitations;
use Tinvar\Jobs\Job;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\SendMail;
use Tinvar\Orm\Orm;

/**
 * An invite from a tenant's settings page, made and sent on the worker
 * (ADR 0159, after the shop's staff invites, ADR 0076): the link is made
 * here so it is never a row in the jobs table, and mailed at once.
 *
 * It runs as the tenant that dispatched it, as every job does, and does
 * nothing for any other: a payload naming another tenant than the one it
 * runs as was not dispatched by this tenant. The inviter is read again, and
 * Invitations asks the InvitePolicy again of them as they are now -- one
 * removed or narrowed since sends nothing, and that is not retried.
 */
#[Queue(SendMail::QUEUE, tries: 5, backoff: [10, 60, 300, 900], timeout: 60)]
final readonly class SendTenantInvite implements Job
{
    public function __construct(
        public string $inviter,
        public string $email,
        public string $tenant,
        public string $panel,
        public string $role,
    ) {}

    public function handle(Orm $orm, Invitations $invitations, UserLookup $users, TenantLinks $links, TenantInviteMails $mails, Mailer $mailer, TenantProfiles $profiles): void
    {
        if ($this->tenant === '' || $orm->tenant() !== $this->tenant) {
            return;
        }
        $inviter = $users->find($this->inviter);
        if ($inviter === null) {
            return;
        }

        try {
            $sent = $invitations->invite($inviter, $this->email, $this->tenant, $this->panel, $this->role, $links->originFor($this->tenant));
        } catch (HttpException) {
            return;
        }

        $mailer->send($mails->invite(
            $sent['invitation'],
            $sent['url'],
            $profiles->profile($this->tenant)->name ?? $this->tenant,
            $inviter instanceof AccountUser ? $inviter->accountName() : $inviter->authIdentifier(),
        ));
    }
}
