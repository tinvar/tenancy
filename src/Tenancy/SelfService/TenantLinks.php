<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Identity\CanonicalOrigins;

/**
 * The origin a tenant's emailed links are built on (ADR 0161): its own
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

    /**
     * Whether $tenant's links are built on a host of its own -- its
     * subdomain, or a verified custom domain -- rather than the platform's
     * origin, which every tenant without one shares. An invite to one is
     * accepted there, on its own host, by the page that reads its tenant
     * from that host; never on the shared one (ADR 0182).
     */
    public function hasOwnHost(string $tenant): bool
    {
        return $this->originFor($tenant) !== $this->canonical->platform;
    }
}
