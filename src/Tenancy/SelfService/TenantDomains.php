<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Carbon\CarbonImmutable;
use Tinvar\Identity\CustomDomain;
use Tinvar\Identity\CustomDomains;
use Tinvar\Identity\Realm;
use Tinvar\Orm\Connection;
use Tinvar\Orm\NamesDriver;
use Tinvar\Orm\Orm;
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
 * On Postgres the table has the tenant policy (Table::tenant()), and the
 * application's role is held to it: a tenant's own read or write runs as
 * that tenant (Orm::actAs()), whatever tenant is in force -- another, on
 * the switcher, or none, in a job -- and a host's holder is read inside
 * acrossTenants(), then written as the tenant found. Without that, the
 * policy hid every domain from the host lookups, which run with no tenant,
 * and no custom domain was ever served.
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

    private Connection $connection;

    private TimestampStorage $timestamps;

    /** @var \Closure(): CarbonImmutable */
    private \Closure $clock;

    /**
     * @param Orm $orm whose connection the table is on, and whose tenant each statement runs as
     * @param (\Closure(): CarbonImmutable)|null $clock now; the system's, in UTC, unless given
     */
    public function __construct(
        private Orm $orm,
        private string $table = self::TABLE,
        ?\Closure $clock = null,
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name the custom domains will write.");
        }
        $this->connection = $orm->statements();
        $this->timestamps = TimestampStorage::for($this->connection);
        $this->clock = $clock ?? static fn (): CarbonImmutable => CarbonImmutable::now('UTC');
    }

    /** The table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = self::TABLE, bool $ifNotExists = false): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name the custom domains will write.");
        }

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
            self::reverificationColumns($domains);
            $domains->unique(['tenant_id', 'host'], "{$table}_tenant_host");
            $domains->index(['status', 'checked_at'], "{$table}_due");
            $domains->index(['status', 'next_check_at'], "{$table}_next_check");
        }, $ifNotExists);
    }

    /**
     * Adds the re-verification's columns (ADR 0198) to a table define()
     * made before them, for an application's migration. Every domain in
     * service is then due at the next run of ReverifyTenantDomains.
     */
    public static function defineReverification(Schema $schema, string $table = self::TABLE): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name the custom domains will write.");
        }

        $schema->table($table, static function (Table $domains) use ($table): void {
            self::reverificationColumns($domains);
            $domains->index(['status', 'next_check_at'], "{$table}_next_check");
        });
    }

    private static function reverificationColumns(Table $domains): void
    {
        // When the run of checks that found the ownership record missing
        // began; when the schedule checks the domain next; and why it went
        // out of service (DomainLapse), while it is out. A string, not an
        // enum's CHECK, so a cause added later needs no change to the table.
        $domains->timestamp('unproved_since')->nullable();
        $domains->timestamp('next_check_at')->nullable();
        $domains->string('lapse', 16)->nullable();
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
            $this->as($tenant, fn (): array => $this->connection->select("SELECT * FROM {$this->table} WHERE tenant_id = ? ORDER BY created_at, host", [$tenant])),
        );
    }

    /** One of the tenant's domains, by id; null for one that is not the tenant's. */
    public function ofTenant(string $tenant, string $id): ?TenantDomain
    {
        $rows = $this->as($tenant, fn (): array => $this->connection->select("SELECT * FROM {$this->table} WHERE tenant_id = ? AND id = ?", [$tenant, $id]));

        return $rows === [] ? null : $this->domain($rows[0]);
    }

    public function countForTenant(string $tenant): int
    {
        $rows = $this->as($tenant, fn (): array => $this->connection->select("SELECT COUNT(*) AS n FROM {$this->table} WHERE tenant_id = ?", [$tenant]));

        return (int) ($rows[0]['n'] ?? 0);
    }

    /**
     * A pending claim of $host for $tenant, as add(), when the tenant has
     * fewer than $limit domains; null when it has that many already.
     *
     * The count and the insert in one transaction, under a lock on the
     * tenant's domains that a second add of the tenant's waits on until the
     * first commits -- so two at once cannot both count the other's
     * uncommitted row as absent and both slip under the limit:
     *
     *  - Postgres: a transaction-scoped advisory lock on the tenant, held to
     *    the outermost commit; READ COMMITTED's row locks cannot hold rows
     *    not yet written.
     *  - MySQL: a locking read of the tenant's rows, whose next-key locks
     *    hold off another insert of the tenant's to the commit; two at once
     *    may deadlock instead, and the one MySQL rolls back is refused.
     *  - SQLite: the transaction's write lock, begun IMMEDIATE
     *    (PdoConnection::sqlite()), holds the whole database from before the
     *    count; on a connection begun DEFERRED the second writer's insert
     *    fails rather than overtake the first's count.
     *
     * A lock not had fails the add, by throwing, rather than count without it.
     */
    public function addWithin(string $tenant, string $host, int $limit): ?TenantDomain
    {
        return $this->as($tenant, fn (): ?TenantDomain => $this->connection->transaction(function () use ($tenant, $host, $limit): ?TenantDomain {
            match ($this->connection instanceof NamesDriver ? $this->connection->driver() : 'sqlite') {
                'pgsql' => $this->connection->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["tinvar-tenant-domains:{$this->table}:{$tenant}"]),
                'mysql' => $this->connection->select("SELECT id FROM {$this->table} WHERE tenant_id = ? FOR UPDATE", [$tenant]),
                default => [],
            };

            return $this->countForTenant($tenant) >= $limit ? null : $this->add($tenant, $host);
        }));
    }

    /**
     * A pending claim of $host for $tenant, with a fresh ownership token.
     *
     * @throws \RuntimeException when the tenant has claimed the host already
     */
    public function add(string $tenant, string $host): TenantDomain
    {
        $domain = new TenantDomain(Uuid::v7(), self::tenant($tenant), self::host($host), TenantDomainStatus::Pending, bin2hex(random_bytes(16)), '', $this->now());
        $this->as($tenant, fn (): int => $this->connection->execute(
            "INSERT INTO {$this->table} (id, tenant_id, host, held_host, status, token, reason, created_at) VALUES (?, ?, ?, NULL, ?, ?, '', ?)",
            [$domain->id, $domain->tenant, $domain->host, $domain->status->value, $domain->token, $this->timestamps->format($domain->createdAt)],
        ));

        return $domain;
    }

    /** Removes one of the tenant's domains: no longer served, from the next request. */
    public function remove(string $tenant, string $id): bool
    {
        return $this->as($tenant, fn (): int => $this->connection->execute("DELETE FROM {$this->table} WHERE tenant_id = ? AND id = ?", [$tenant, $id])) === 1;
    }

    /**
     * Puts a domain the tenant proved in service: Verified, holding its
     * host. False when another tenant holds the host already -- the check
     * and the database's unique index both say so.
     */
    public function activate(string $tenant, string $id, CarbonImmutable $at, string $reason, ?CarbonImmutable $next = null): bool
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
            return $this->as($domain->tenant, fn (): bool => $this->connection->transaction(fn (): bool => $this->connection->execute(
                "UPDATE {$this->table} SET status = ?, held_host = host, reason = ?, checked_at = ?, verified_at = ?, failing_since = NULL, deactivated_at = NULL, "
                . 'unproved_since = NULL, lapse = NULL, next_check_at = ? WHERE tenant_id = ? AND id = ?',
                [TenantDomainStatus::Verified->value, $reason, $when, $when, $next === null ? null : $this->timestamps->format($next), $domain->tenant, $id],
            ) === 1));
        } catch (\PDOException) {
            // Another tenant's proof of the same host committed in between:
            // the unique index on held_host refused this one.
            return false;
        }
    }

    /**
     * A check of a domain not in service found it not yet proved: why, and
     * when; and, for a lapsed one the schedule still checks, when next.
     */
    public function unproved(string $tenant, string $id, CarbonImmutable $at, string $reason, ?CarbonImmutable $next = null): void
    {
        $this->as($tenant, fn (): int => $this->connection->execute(
            "UPDATE {$this->table} SET checked_at = ?, reason = ?, next_check_at = ? WHERE tenant_id = ? AND id = ? AND held_host IS NULL",
            [$this->timestamps->format($at), $reason, $next === null ? null : $this->timestamps->format($next), $tenant, $id],
        ));
    }

    // The re-verification's (ADR 0198): read across tenants, written as the tenant.

    /**
     * The domain in service on $host, whoever's it is; null for a host
     * nobody holds.
     */
    public function holding(string $host): ?TenantDomain
    {
        $rows = $this->orm->acrossTenants(fn (): array => $this->connection->select("SELECT * FROM {$this->table} WHERE held_host = ?", [$host]));

        return $rows === [] ? null : $this->domain($rows[0]);
    }

    /**
     * The domains the schedule checks that are due at $now: in service, or
     * out of it and not yet released, with no check booked or one booked at
     * or before $now -- the longest waiting first, at most $limit.
     *
     * @param int<1, max> $limit
     * @return list<TenantDomain>
     */
    public function dueForCheck(CarbonImmutable $now, int $limit): array
    {
        $rows = $this->orm->acrossTenants(fn (): array => $this->connection->select(
            "SELECT * FROM {$this->table} WHERE status IN (?, ?, ?) AND (next_check_at IS NULL OR next_check_at <= ?) "
            . 'ORDER BY next_check_at IS NOT NULL, next_check_at, id LIMIT ' . max(1, $limit),
            [TenantDomainStatus::Verified->value, TenantDomainStatus::Failing->value, TenantDomainStatus::Deactivated->value, $this->timestamps->format($now)],
        ));

        return array_map($this->domain(...), $rows);
    }

    /**
     * Claims $domain's check for one run: books its next check at $until if
     * it is still due at $now and in the status it was read in. False when
     * another run or a change took it first, so it is not checked twice.
     */
    public function claimCheck(TenantDomain $domain, CarbonImmutable $now, CarbonImmutable $until): bool
    {
        return $this->as($domain->tenant, fn (): int => $this->connection->execute(
            "UPDATE {$this->table} SET next_check_at = ? WHERE tenant_id = ? AND id = ? AND status = ? AND (next_check_at IS NULL OR next_check_at <= ?)",
            [$this->timestamps->format($until), $domain->tenant, $domain->id, $domain->status->value, $this->timestamps->format($now)],
        )) === 1;
    }

    /**
     * A check of a domain in service that left it in service: Verified or
     * Failing, with the runs of failures it is in. False when it is no
     * longer in service -- removed, or lapsed by another check.
     */
    public function rechecked(
        TenantDomain $domain,
        CarbonImmutable $at,
        CarbonImmutable $next,
        TenantDomainStatus $status,
        ?CarbonImmutable $failingSince,
        ?CarbonImmutable $unprovedSince,
        string $reason,
    ): bool {
        if (!$status->active()) {
            throw new \LogicException('A domain rechecked into service is Verified or Failing.');
        }
        $format = fn (?CarbonImmutable $moment): ?string => $moment === null ? null : $this->timestamps->format($moment);

        return $this->as($domain->tenant, fn (): int => $this->connection->execute(
            "UPDATE {$this->table} SET status = ?, checked_at = ?, next_check_at = ?, failing_since = ?, unproved_since = ?, reason = ? "
            . 'WHERE tenant_id = ? AND id = ? AND held_host IS NOT NULL',
            [$status->value, $format($at), $format($next), $format($failingSince), $format($unprovedSince), $reason, $domain->tenant, $domain->id],
        )) === 1;
    }

    /**
     * Takes a domain out of service, from $at, because of $lapse:
     * Deactivated and holding its host no longer, so from the next lookup
     * it is nobody's. False when it was not in service.
     */
    public function lapse(TenantDomain $domain, CarbonImmutable $at, ?CarbonImmutable $next, DomainLapse $lapse, string $reason): bool
    {
        $when = $this->timestamps->format($at);

        return $this->as($domain->tenant, fn (): int => $this->connection->execute(
            "UPDATE {$this->table} SET status = ?, held_host = NULL, checked_at = ?, deactivated_at = ?, next_check_at = ?, failing_since = NULL, "
            . 'unproved_since = NULL, lapse = ?, reason = ? WHERE tenant_id = ? AND id = ? AND held_host IS NOT NULL',
            [TenantDomainStatus::Deactivated->value, $when, $when, $next === null ? null : $this->timestamps->format($next), $lapse->value, $reason, $domain->tenant, $domain->id],
        )) === 1;
    }

    /**
     * The out-of-service domains whose claims are due to be released: out
     * of service since before $cutoff, the longest first, at most $limit.
     *
     * @param int<1, max> $limit
     * @return list<TenantDomain>
     */
    public function releasable(CarbonImmutable $cutoff, int $limit): array
    {
        $rows = $this->orm->acrossTenants(fn (): array => $this->connection->select(
            "SELECT * FROM {$this->table} WHERE status = ? AND held_host IS NULL AND deactivated_at < ? ORDER BY deactivated_at, id LIMIT " . max(1, $limit),
            [TenantDomainStatus::Deactivated->value, $this->timestamps->format($cutoff)],
        ));

        return array_map($this->domain(...), $rows);
    }

    /**
     * Releases a lapsed claim: Pending again, with a fresh token, so the
     * record that proved it before proves nothing now, and the schedule no
     * longer checks it. False when it was not out of service.
     */
    public function release(TenantDomain $domain, CarbonImmutable $at, string $reason): bool
    {
        return $this->as($domain->tenant, fn (): int => $this->connection->execute(
            "UPDATE {$this->table} SET status = ?, token = ?, checked_at = ?, deactivated_at = NULL, next_check_at = NULL, failing_since = NULL, "
            . 'unproved_since = NULL, lapse = NULL, reason = ? WHERE tenant_id = ? AND id = ? AND status = ? AND held_host IS NULL',
            [TenantDomainStatus::Pending->value, bin2hex(random_bytes(16)), $this->timestamps->format($at), $reason, $domain->tenant, $domain->id, TenantDomainStatus::Deactivated->value],
        )) === 1;
    }

    /** The tenant serving $host, or null: a pending, deactivated or unknown host is nobody's. */
    public function tenantForHost(string $host): ?string
    {
        $rows = $this->orm->acrossTenants(fn (): array => $this->connection->select("SELECT tenant_id FROM {$this->table} WHERE held_host = ?", [$host]));
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
        $rows = $this->as($tenant, fn (): array => $this->connection->select(
            "SELECT held_host FROM {$this->table} WHERE tenant_id = ? AND held_host IS NOT NULL ORDER BY verified_at, held_host LIMIT 1",
            [$tenant],
        ));
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
        $rows = $this->orm->acrossTenants(fn (): array => $this->connection->select(
            "SELECT * FROM {$this->table} WHERE held_host IS NOT NULL AND (checked_at IS NULL OR checked_at < ?) "
            . 'ORDER BY checked_at IS NOT NULL, checked_at, held_host LIMIT ' . max(1, $limit),
            [$this->timestamps->format($since)],
        ));

        return array_map(fn (array $row): CustomDomain => $this->domain($row)->custom(), $rows);
    }

    public function find(string $host): ?CustomDomain
    {
        $rows = $this->orm->acrossTenants(fn (): array => $this->connection->select("SELECT * FROM {$this->table} WHERE held_host = ?", [$host]));
        if ($rows !== []) {
            return $this->domain($rows[0])->custom();
        }

        // Out of service: the last one deactivated, which nothing serves.
        $rows = $this->orm->acrossTenants(fn (): array => $this->connection->select(
            "SELECT * FROM {$this->table} WHERE host = ? AND status = ? ORDER BY deactivated_at DESC LIMIT 1",
            [$host, TenantDomainStatus::Deactivated->value],
        ));

        return $rows === [] ? null : $this->domain($rows[0])->custom();
    }

    public function passed(string $host, \DateTimeImmutable $at): void
    {
        // Where it points is all this check read: a run of missing
        // ownership records (ADR 0198) goes on, and keeps it failing.
        $this->asHolderOf($host, fn (): int => $this->connection->execute(
            "UPDATE {$this->table} SET status = CASE WHEN unproved_since IS NULL THEN ? ELSE ? END, checked_at = ?, failing_since = NULL, "
            . 'reason = CASE WHEN unproved_since IS NULL THEN ? ELSE reason END WHERE held_host = ?',
            [TenantDomainStatus::Verified->value, TenantDomainStatus::Failing->value, $this->timestamps->format($at), 'It points at the platform.', $host],
        ));
    }

    public function failed(string $host, \DateTimeImmutable $at, \DateTimeImmutable $failingSince): void
    {
        $this->asHolderOf($host, fn (): int => $this->connection->execute(
            "UPDATE {$this->table} SET status = ?, checked_at = ?, failing_since = ?, reason = ? WHERE held_host = ?",
            [
                TenantDomainStatus::Failing->value,
                $this->timestamps->format($at),
                $this->timestamps->format($failingSince),
                'Its DNS could not be read at the last check. It stays in service for now; check its records.',
                $host,
            ],
        ));
    }

    public function deactivate(string $host, string $reason, \DateTimeImmutable $at): void
    {
        $this->asHolderOf($host, fn (): int => $this->connection->execute(
            "UPDATE {$this->table} SET status = ?, held_host = NULL, checked_at = ?, deactivated_at = ?, unproved_since = NULL, lapse = ?, reason = ? WHERE held_host = ?",
            [TenantDomainStatus::Deactivated->value, $this->timestamps->format($at), $this->timestamps->format($at), DomainLapse::Pointing->value, $reason, $host],
        ));
    }

    /**
     * $work as $tenant, so the tenant policy shows it the tenant's rows
     * whatever tenant is in force: another, or none.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function as(string $tenant, \Closure $work): mixed
    {
        return $this->orm->actAs(self::tenant($tenant), $work);
    }

    /**
     * $work as the tenant holding $host, found across tenants; nothing for
     * a host nobody holds. A write as that tenant names whose row it
     * changed, where one inside acrossTenants() could name nobody's (ADR
     * 0122); and one whose holder changed in between matches no row.
     *
     * @param \Closure(): int $work
     */
    private function asHolderOf(string $host, \Closure $work): void
    {
        $tenant = $this->tenantForHost($host);
        if ($tenant !== null) {
            $this->as($tenant, $work);
        }
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
            unprovedSince: $at($row['unproved_since'] ?? null),
            nextCheckAt: $at($row['next_check_at'] ?? null),
            lapse: \is_string($row['lapse'] ?? null) ? DomainLapse::tryFrom($row['lapse']) : null,
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
