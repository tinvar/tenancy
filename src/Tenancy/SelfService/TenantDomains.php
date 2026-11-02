<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Carbon\CarbonImmutable;
use Tinvar\Identity\CustomDomain;
use Tinvar\Identity\CustomDomains;
use Tinvar\Identity\Realm;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Orm\TimestampStorage;
use Tinvar\Support\Uuid;

/**
 * The tenants' custom domains, in a table of tinvar/tenancy's own (ADR
 * 0161): what the domains page adds, checks and removes, and the records
 * the framework's re-verification reads and writes (CustomDomains) -- the
 * daily VerifyCustomDomains, and Caddy's TlsAskEndpoint.
 *
 * Two kinds of read, kept apart:
 *
 * - **A tenant's own.** Every method taking a tenant names it in its WHERE,
 *   and the pages hand it the tenant the request runs as (Orm::tenant()),
 *   never one a request posts. A domain id from another tenant is not
 *   found.
 * - **A host's holder.** Which tenant serves a host is read across tenants
 *   by its nature -- a request arrives with a host and no tenant yet -- and
 *   only active domains answer: tenantForHost(), customDomain() and
 *   realm() are what the application's TenantHosts, Domains and Realms
 *   lookups read, and a pending or deactivated domain is nobody's.
 *
 * A host is held by at most one tenant, by the database: an active domain
 * sets `held_host`, which is unique, so two tenants proving one host at
 * once cannot both be served on it. Pending claims hold nothing -- anyone
 * may claim a host, and only the one who proves it holds it -- so a tenant
 * cannot squat a domain it does not own to keep its owner off.
 */
final readonly class TenantDomains implements CustomDomains
{
    public const string TABLE = 'tenant_domains';

    private TimestampStorage $timestamps;

    /** @var \Closure(): CarbonImmutable */
    private \Closure $clock;

    /** @param (\Closure(): CarbonImmutable)|null $clock now; the system's, in UTC, unless given */
    public function __construct(
        private Connection $connection,
        private string $table = self::TABLE,
        ?\Closure $clock = null,
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name the custom domains will write.");
        }
        $this->timestamps = TimestampStorage::for($connection);
        $this->clock = $clock ?? static fn (): CarbonImmutable => CarbonImmutable::now('UTC');
    }

    /** The table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = self::TABLE, bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);

        $schema->create($table, static function (Table $domains) use ($table): void {
            $domains->uuid('id')->primary();
            $domains->tenant();
            $domains->string('host', 253);
            // The host while the domain is served, null otherwise: unique, so
            // no two tenants are ever served on one host.
            $domains->string('held_host', 253)->nullable()->unique();
            $domains->enum('status', TenantDomainStatus::class);
            $domains->string('token', 64);
            $domains->text('reason');
            $domains->timestamp('created_at');
            $domains->timestamp('checked_at')->nullable();
            $domains->timestamp('verified_at')->nullable();
            $domains->timestamp('failing_since')->nullable();
            $domains->timestamp('deactivated_at')->nullable();
            $domains->unique(['tenant_id', 'host'], "{$table}_tenant_host");
            $domains->index(['status', 'checked_at'], "{$table}_due");
        }, $ifNotExists);
    }

    public function now(): CarbonImmutable
    {
        return ($this->clock)();
    }

    /**
     * The tenant's domains, oldest first.
     *
     * @return list<TenantDomain>
     */
    public function forTenant(string $tenant): array
    {
        return array_map(
            $this->domain(...),
            $this->connection->select("SELECT * FROM {$this->table} WHERE tenant_id = ? ORDER BY created_at, host", [self::tenant($tenant)]),
        );
    }

    /** One of the tenant's domains, by id; null for one that is not the tenant's. */
    public function ofTenant(string $tenant, string $id): ?TenantDomain
    {
        $rows = $this->connection->select("SELECT * FROM {$this->table} WHERE tenant_id = ? AND id = ?", [self::tenant($tenant), $id]);

        return $rows === [] ? null : $this->domain($rows[0]);
    }

    public function countForTenant(string $tenant): int
    {
        $rows = $this->connection->select("SELECT COUNT(*) AS n FROM {$this->table} WHERE tenant_id = ?", [self::tenant($tenant)]);

        return (int) ($rows[0]['n'] ?? 0);
    }

    /**
     * A pending claim of $host for $tenant, with a fresh ownership token.
     *
     * @throws \RuntimeException when the tenant has claimed the host already
     */
    public function add(string $tenant, string $host): TenantDomain
    {
        $domain = new TenantDomain(Uuid::v7(), self::tenant($tenant), self::host($host), TenantDomainStatus::Pending, bin2hex(random_bytes(16)), '', $this->now());
        $this->connection->execute(
            "INSERT INTO {$this->table} (id, tenant_id, host, held_host, status, token, reason, created_at) VALUES (?, ?, ?, NULL, ?, ?, '', ?)",
            [$domain->id, $domain->tenant, $domain->host, $domain->status->value, $domain->token, $this->timestamps->format($domain->createdAt)],
        );

        return $domain;
    }

    /** Removes one of the tenant's domains: no longer served, from the next request. */
    public function remove(string $tenant, string $id): bool
    {
        return $this->connection->execute("DELETE FROM {$this->table} WHERE tenant_id = ? AND id = ?", [self::tenant($tenant), $id]) === 1;
    }

    /**
     * Puts a domain the tenant proved in service: Verified, holding its
     * host. False when another tenant holds the host already -- the check
     * and the database's unique index both say so.
     */
    public function activate(string $tenant, string $id, CarbonImmutable $at, string $reason): bool
    {
        $domain = $this->ofTenant($tenant, $id);
        if ($domain === null) {
            return false;
        }
        $holder = $this->tenantForHost($domain->host);
        if ($holder !== null && $holder !== $domain->tenant) {
            return false;
        }

        $when = $this->timestamps->format($at);
        try {
            // In a transaction of its own -- a savepoint inside the action's --
            // so a refusal by the index leaves the action's able to go on.
            return $this->connection->transaction(fn (): bool => $this->connection->execute(
                "UPDATE {$this->table} SET status = ?, held_host = host, reason = ?, checked_at = ?, verified_at = ?, failing_since = NULL, deactivated_at = NULL "
                . 'WHERE tenant_id = ? AND id = ?',
                [TenantDomainStatus::Verified->value, $reason, $when, $when, $domain->tenant, $id],
            ) === 1);
        } catch (\PDOException) {
            // Another tenant's proof of the same host committed in between:
            // the unique index on held_host refused this one.
            return false;
        }
    }

    /** A check of a domain not in service found it not yet proved: why, and when. */
    public function unproved(string $tenant, string $id, CarbonImmutable $at, string $reason): void
    {
        $this->connection->execute(
            "UPDATE {$this->table} SET checked_at = ?, reason = ? WHERE tenant_id = ? AND id = ? AND held_host IS NULL",
            [$this->timestamps->format($at), $reason, self::tenant($tenant), $id],
        );
    }

    /** The tenant serving $host, or null: a pending, deactivated or unknown host is nobody's. */
    public function tenantForHost(string $host): ?string
    {
        $rows = $this->connection->select("SELECT tenant_id FROM {$this->table} WHERE held_host = ?", [$host]);
        $tenant = $rows[0]['tenant_id'] ?? null;

        return \is_string($tenant) && $tenant !== '' ? $tenant : null;
    }

    /**
     * The tenant's first domain in service, a host, or null: its address on
     * the switcher, and the primary domain its emailed links are built on
     * (CanonicalOrigins, which checks it again).
     */
    public function primaryFor(string $tenant): ?string
    {
        $rows = $this->connection->select(
            "SELECT held_host FROM {$this->table} WHERE tenant_id = ? AND held_host IS NOT NULL ORDER BY verified_at, held_host LIMIT 1",
            [self::tenant($tenant)],
        );
        $host = $rows[0]['held_host'] ?? null;

        return \is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * What Domains serves a custom domain as (`custom:`, ADR 0149): the
     * route parameters of its `customAs` pattern, `{tenant}` as the tenant's
     * slug, or null for a host no tenant serves.
     *
     * @param \Closure(string): ?string $slugOf a tenant's slug
     * @return array<string, string>|null
     */
    public function customDomain(string $host, \Closure $slugOf, string $parameter = 'tenant'): ?array
    {
        $tenant = $this->tenantForHost($host);
        $slug = $tenant === null ? null : $slugOf($tenant);

        return \is_string($slug) && $slug !== '' ? [$parameter => $slug] : null;
    }

    /**
     * The realm a host is when a tenant serves it (ADR 0039): what the
     * application's Realms lookup answers for a custom domain, on https,
     * held to $panel -- or null for a host no tenant serves.
     */
    public function realm(string $host, string $panel): ?Realm
    {
        $tenant = $this->tenantForHost($host);

        return $tenant === null ? null : Realm::domain("https://{$host}", $tenant, $panel);
    }

    // CustomDomains: the framework's re-verification, over active domains.

    public function due(\DateTimeImmutable $since, int $limit): array
    {
        $rows = $this->connection->select(
            "SELECT * FROM {$this->table} WHERE held_host IS NOT NULL AND (checked_at IS NULL OR checked_at < ?) "
            . 'ORDER BY checked_at IS NOT NULL, checked_at, held_host LIMIT ' . max(1, $limit),
            [$this->timestamps->format($since)],
        );

        return array_map(fn (array $row): CustomDomain => $this->domain($row)->custom(), $rows);
    }

    public function find(string $host): ?CustomDomain
    {
        $rows = $this->connection->select("SELECT * FROM {$this->table} WHERE held_host = ?", [$host]);
        if ($rows !== []) {
            return $this->domain($rows[0])->custom();
        }

        // Out of service: the last one deactivated, which nothing serves.
        $rows = $this->connection->select(
            "SELECT * FROM {$this->table} WHERE host = ? AND status = ? ORDER BY deactivated_at DESC LIMIT 1",
            [$host, TenantDomainStatus::Deactivated->value],
        );

        return $rows === [] ? null : $this->domain($rows[0])->custom();
    }

    public function passed(string $host, \DateTimeImmutable $at): void
    {
        $this->connection->execute(
            "UPDATE {$this->table} SET status = ?, checked_at = ?, failing_since = NULL, reason = ? WHERE held_host = ?",
            [TenantDomainStatus::Verified->value, $this->timestamps->format($at), 'It points at the platform.', $host],
        );
    }

    public function failed(string $host, \DateTimeImmutable $at, \DateTimeImmutable $failingSince): void
    {
        $this->connection->execute(
            "UPDATE {$this->table} SET status = ?, checked_at = ?, failing_since = ?, reason = ? WHERE held_host = ?",
            [
                TenantDomainStatus::Failing->value,
                $this->timestamps->format($at),
                $this->timestamps->format($failingSince),
                'Its DNS could not be read at the last check. It stays in service for now; check its records.',
                $host,
            ],
        );
    }

    public function deactivate(string $host, string $reason, \DateTimeImmutable $at): void
    {
        $this->connection->execute(
            "UPDATE {$this->table} SET status = ?, held_host = NULL, checked_at = ?, deactivated_at = ?, reason = ? WHERE held_host = ?",
            [TenantDomainStatus::Deactivated->value, $this->timestamps->format($at), $this->timestamps->format($at), $reason, $host],
        );
    }

    /** @param array<string, mixed> $row */
    private function domain(array $row): TenantDomain
    {
        $at = static fn (mixed $value): ?CarbonImmutable => \is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC')->utc() : null;

        return new TenantDomain(
            id: (string) $row['id'],
            tenant: (string) $row['tenant_id'],
            host: (string) $row['host'],
            status: TenantDomainStatus::from((string) $row['status']),
            token: (string) $row['token'],
            reason: (string) ($row['reason'] ?? ''),
            createdAt: $at($row['created_at'] ?? null) ?? CarbonImmutable::createFromTimestamp(0, 'UTC'),
            checkedAt: $at($row['checked_at'] ?? null),
            verifiedAt: $at($row['verified_at'] ?? null),
            failingSince: $at($row['failing_since'] ?? null),
            deactivatedAt: $at($row['deactivated_at'] ?? null),
        );
    }

    /** A tenant, never none: a domain is always some tenant's, and '' would match nobody's rows by accident. */
    private static function tenant(string $tenant): string
    {
        if ($tenant === '') {
            throw new \LogicException('Custom domains are a tenant\'s: this ran with no tenant in force.');
        }

        return $tenant;
    }

    private static function host(string $host): string
    {
        if (\Tinvar\Http\HostName::normalize($host) !== $host) {
            throw new \InvalidArgumentException("'{$host}' is not a normalized host name.");
        }

        return $host;
    }
}
