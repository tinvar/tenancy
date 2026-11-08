<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

/**
 * A TenantTree that answers its subtrees a page at a time (ADR 0196): what
 * lets the Orm walk a subtree of any size -- withinSubtreePages(),
 * forEachTenant() -- holding a page of it, and refuse one too large to
 * read at once having read no more of it than the limit.
 *
 * A tree that implements TenantTree alone still pages: TenantHierarchy
 * reads its descendantsOf() whole and pages that. DatabaseTenants
 * implements this one over its table.
 */
interface PagedTenantTree extends TenantTree
{
    /**
     * The first $limit tenants below $tenant, at any depth and not $tenant
     * itself, whose ids sort after $after -- all of them for null -- in byte
     * order (strcmp(); a database's binary or "C" collation): from the same
     * record as descendantsOf(), one recursive query or a path prefix per
     * page.
     *
     * @param int $limit 1 or more
     * @return list<string>
     */
    public function descendantsAfter(string $tenant, ?string $after, int $limit): array;
}
