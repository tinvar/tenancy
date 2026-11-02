<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Identity\CanonicalOrigins;

/**
 * The origin a tenant's emailed links are built on (ADR 0159): its own
 * host, where the page an invite lands on places it -- TenantAddresses, its
 * subdomain or its verified custom domain -- or else its canonical origin
 * (CanonicalOrigins). Never a request's Host header: an invite is made on a
 * worker, and a Host is whatever its sender typed.
 */
final readonly class TenantLinks
{
    public function __construct(
        private CanonicalOrigins $canonical,
        private ?TenantAddresses $addresses = null,
        private bool $localHttp = false,
    ) {}

    public function originFor(string $tenant): string
    {
        $own = $this->addresses?->originFor($tenant);
        if ($own !== null && $own !== '') {
            return CanonicalOrigins::origin($own, $this->localHttp);
        }

        return $this->canonical->forTenant($tenant);
    }
}
