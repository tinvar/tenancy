<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;

/**
 * The custom domains page: the tenant's domains, how many it may have, and
 * whether the signed-in member may add, check and remove them -- which
 * forms to draw, never what the actions allow -- and the actions the forms
 * post to, named as the application's class is.
 */
final readonly class TenantDomainsPageData extends Data
{
    /** @param list<TenantDomainData> $domains */
    public function __construct(
        public array $domains,
        public int $limit,
        public bool $canManage,
        public string $addAction,
        public string $checkAction,
        public string $removeAction,
    ) {}
}
