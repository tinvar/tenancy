<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;
use Tinvar\Tenancy\SelfService\TenantChoice;
use Tinvar\Tenancy\SelfService\TenantSwitchVia;

/**
 * One tenant on the switcher page: its id (what a switch posts), its name,
 * how it is reached, and -- for one on a host of its own -- that host.
 */
final readonly class TenantChoiceData extends Data
{
    public function __construct(
        public string $tenant,
        public string $name,
        public TenantSwitchVia $via,
        public string $host,
    ) {}

    public static function of(TenantChoice $choice): self
    {
        $host = $choice->origin === '' ? '' : (string) parse_url($choice->origin, PHP_URL_HOST);

        return new self($choice->tenant, $choice->name, $choice->via, $host);
    }
}
