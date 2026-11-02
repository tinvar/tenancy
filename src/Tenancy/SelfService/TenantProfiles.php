<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * The application's tenants, as the self-service pages read and change
 * them (ADR 0155): a name, and a slug -- the label of the tenant's
 * subdomain. DatabaseTenants keeps them in a table of its own; an
 * application with its own tenants table implements this over it.
 *
 * Only ever asked about the tenant a request runs as, or the tenants a
 * signed-in user holds a membership of: the pages never take a tenant from
 * what a request posts.
 */
interface TenantProfiles
{
    /** The tenant's profile, or null for one there is none of. */
    public function profile(string $tenant): ?TenantProfile;

    public function rename(string $tenant, string $name): void;

    /**
     * Gives the tenant a new slug, already checked against SlugRules.
     *
     * @throws SlugTaken when another tenant holds it -- checked by the store, which a
     *         check before it cannot replace: two tenants asking at once would both pass
     */
    public function changeSlug(string $tenant, string $slug): void;

    /** The tenant whose slug this is, or null. */
    public function tenantForSlug(string $slug): ?string;
}
