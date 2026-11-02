<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * Tenants on subdomains of the platform's domain, `{slug}.example.com`, and
 * on the custom domains they verified: the TenantAddresses an application
 * that routes tenants by host (ADR 0149) gives the switcher.
 *
 *     new SubdomainAddresses('example.com', $tenants->slugOf(...), customDomain: $domains->primaryFor(...))
 *
 * A verified custom domain is a tenant's address in place of its subdomain,
 * as it is where its emailed links land. Either is only offered once
 * TenantSwitchboard finds it a realm of the application's.
 */
final readonly class SubdomainAddresses implements TenantAddresses
{
    /**
     * @param string $root the platform's domain the slugs are labels under, normalized
     * @param \Closure(string): ?string $slugOf a tenant's slug, or null for one with none
     * @param string $scheme https, or http on localhost in development
     * @param int|null $port the port, when it is not the scheme's default
     * @param (\Closure(string): ?string)|null $customDomain a tenant's verified custom domain,
     *        a normalized host, or null -- TenantDomains::primaryFor()
     */
    public function __construct(
        private string $root,
        private \Closure $slugOf,
        private string $scheme = 'https',
        private ?int $port = null,
        private ?\Closure $customDomain = null,
    ) {
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new \InvalidArgumentException('A tenant is served on https, or on http on localhost in development.');
        }
    }

    public function originFor(string $tenant): ?string
    {
        if ($tenant === '') {
            return null;
        }

        $custom = $this->customDomain === null ? null : ($this->customDomain)($tenant);
        if (\is_string($custom) && $custom !== '') {
            return "https://{$custom}";
        }

        $slug = ($this->slugOf)($tenant);
        if (!\is_string($slug) || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $slug) !== 1) {
            return null;
        }

        return "{$this->scheme}://{$slug}.{$this->root}" . ($this->port === null ? '' : ":{$this->port}");
    }
}
