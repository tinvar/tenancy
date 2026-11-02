<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Auth\Authenticatable;
use Tinvar\Jobs\Dispatcher;

/** Invites sent by a job on the queue (SendTenantInvite): the request answers at once, and the link is made on the worker. */
final readonly class QueuedTenantInvites implements TenantInvites
{
    public function __construct(private Dispatcher $dispatcher) {}

    public function send(Authenticatable $inviter, string $email, string $tenant, string $panel, string $role): void
    {
        $this->dispatcher->dispatch(new SendTenantInvite($inviter->authIdentifier(), $email, $tenant, $panel, $role));
    }
}
