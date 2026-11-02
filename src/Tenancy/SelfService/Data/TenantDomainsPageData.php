<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;

/**
 * The custom domains page: the tenant's domains, how many it may have, and
 * whether the signed-in member may add, check and remove them -- which
 * forms to draw, never what the actions allow.
 */
final readonly class TenantDomainsPageData extends Data
{
    /** @param list<TenantDomainData> $domains */
    public function __construct(
        public array $domains,
        public int $limit,
        public bool $canManage,
    ) {}
}
