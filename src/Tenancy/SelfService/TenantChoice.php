<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * One tenant the switcher offers: its id and name, how it is reached, and
 * -- for one on a host of its own -- the origin the handoff goes to.
 */
final readonly class TenantChoice
{
    /** @param string $origin the origin a Handoff goes to; '' for any other */
    public function __construct(
        public string $tenant,
        public string $name,
        public TenantSwitchVia $via,
        public string $origin = '',
    ) {}
}
