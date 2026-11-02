<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;

/** The tenant switcher page: the tenants the signed-in user may go to from here, the current one first. */
final readonly class TenantSwitchPageData extends Data
{
    /** @param list<TenantChoiceData> $tenants */
    public function __construct(
        public array $tenants,
        public string $current,
    ) {}
}
