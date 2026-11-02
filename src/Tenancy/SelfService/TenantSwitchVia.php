<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/** How the switcher takes a user to one of their tenants. */
enum TenantSwitchVia: string
{
    /** It is the tenant the request runs as: there is nowhere to go. */
    case Current = 'current';

    /** On this host, by a new session running as it (TenantSwitcher, TenantSessions). */
    case Session = 'session';

    /** On its own host, by the cross-host handoff (HandoffEndpoint), which signs the user in there. */
    case Handoff = 'handoff';
}
