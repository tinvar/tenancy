<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * Where each tenant is served, for the tenant switcher (ADR 0155): the
 * origin of a tenant's own host -- its subdomain, or its verified custom
 * domain -- or null for a tenant with none, which a session switches into
 * on the host it is on.
 *
 * The application's word, and checked: TenantSwitchboard offers a tenant
 * on another host only when its origin is a realm the application serves
 * (Realms::forOrigin()) whose tenant it is -- a handoff lands in its
 * realm's tenant exactly. Anything else is not offered, and a switch to it
 * is refused.
 * SubdomainAddresses is one way to answer.
 */
interface TenantAddresses
{
    /** The origin `https://host[:port]` $tenant is served on, or null for none of its own. */
    public function originFor(string $tenant): ?string;
}
