<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\Fakes;

use Tinvar\Http\Request;
use Tinvar\Http\HostName;
use Tinvar\Tenancy\TenantSource;

/**
 * A tenant source a test says the answers of: by host, or one tenant for
 * every request, or none. Each request asked about is recorded.
 *
 *     $source = TenantSourceFake::hosts(['acme.example.test' => 'acme']);
 *     $source = TenantSourceFake::always('acme');
 *     $source = TenantSourceFake::never();
 *
 * Like the real sources, it answers null for what it was not told, and
 * never guesses: a host the test did not name places no tenant, and the
 * middleware's refusal of that is what the test sees. It names a tenant
 * outright, as a signed URL does; SubtreeSourceFake is the one that bounds
 * the request instead, as a host does.
 *
 * Refused in a process that says production (Guard).
 */
class TenantSourceFake implements TenantSource
{
    /** @var list<Request> */
    private array $asked = [];

    /** @param array<string, string>|string|null $answers tenant by host, lowercased; one tenant for all; or none */
    final protected function __construct(private readonly array|string|null $answers)
    {
        Guard::refuseInProduction(static::class);
        if (\is_array($answers)) {
            foreach ($answers as $host => $tenant) {
                if ($host === '' || $tenant === '' || $host !== strtolower($host)) {
                    throw new \InvalidArgumentException('A fake source maps lowercase hosts to tenants, neither empty.');
                }
            }
        } elseif ($answers === '') {
            throw new \InvalidArgumentException('A fake source answers a tenant, not an empty one; never() answers none.');
        }
    }

    /** @param array<string, string> $tenants tenant by host, lowercased */
    public static function hosts(array $tenants): static
    {
        return new static($tenants);
    }

    public static function always(string $tenant): static
    {
        return new static($tenant);
    }

    public static function never(): static
    {
        return new static(null);
    }

    public function tenantFor(Request $request): ?string
    {
        $this->asked[] = $request;
        if (!\is_array($this->answers)) {
            return $this->answers;
        }
        // Read as Host reads it: lowercase, without its port or a trailing
        // dot, and nothing at all for what is not a host name.
        $host = HostName::normalize($request->header('host') ?? '');

        return $host === null ? null : ($this->answers[$host] ?? null);
    }

    /** @return list<Request> every request asked about, oldest first */
    public function asked(): array
    {
        return $this->asked;
    }
}
