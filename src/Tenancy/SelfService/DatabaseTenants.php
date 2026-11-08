<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Connection;
use Tinvar\Orm\NamesDriver;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Orm\TimestampStorage;
use Tinvar\Orm\UniqueViolation;
use Tinvar\Support\Uuid;
use Tinvar\Tenancy\PagedTenantTree;
use Tinvar\Tenancy\TenantDirectory;

/**
 * The tenants, in a table of tinvar/tenancy's own (ADR 0161): an id, a
 * name, a slug -- its subdomain's label -- and a parent. A ready-made
 * TenantProfiles for the settings page, and the TenantDirectory and
 * TenantTree the rest of tenancy reads, from the one table, so they cannot
 * disagree -- paged as well (PagedTenantTree, ADR 0196): one recursive
 * query per page, by the parent column, so a tenant moved by its parent_id
 * is where the next page finds it, in the transaction that moved it. An application with its own tenants table implements those
 * over it instead, and never makes this one.
 *
 * The slug is unique by the database: two tenants taking one at once
 * cannot both have it, and the one refused is told so by the index itself
 * (SlugTaken), never by a read before the write that the other's could
 * overtake.
 *
 * A slug a tenant leaves stays its own for RESERVED_DAYS (90): kept in the
 * slug history (`tenant_slugs` beside `tenants`), where every slug a tenant
 * takes is claimed under one unique key, current and former alike -- so no
 * other tenant takes it while old links, bookmarks and emailed addresses
 * still name it, and Subdomains sends them on to the tenant's new address
 * (movedFrom()). After that the slug is anyone's again. The tenant itself
 * may take it back at any time.
 *
 * A tenant holds its last KEEP_FORMER_SLUGS (3) former slugs and no more:
 * a fourth left lets the oldest go at once, so renaming again and again
 * squats on nothing beyond them. The platform's staff may let any former
 * slug go sooner (releaseSlug()), never one a tenant goes by.
 */
final readonly class DatabaseTenants implements TenantProfiles, TenantDirectory, PagedTenantTree
{
    public const string TABLE = 'tenants';

    /** The slug history's table, beside the default tenants table. */
    public const string SLUGS_TABLE = 'tenant_slugs';

    /** How long a slug a tenant left stays reserved to it, and its old address sends visitors on. */
    public const int RESERVED_DAYS = 90;

    /** How many former slugs one tenant holds at a time, unless told otherwise. */
    public const int KEEP_FORMER_SLUGS = 3;

    private TimestampStorage $timestamps;

    private string $slugs;

    /** @var \Closure(): CarbonImmutable */
    private \Closure $clock;

    /**
     * @param (\Closure(): CarbonImmutable)|null $clock now; the system's, in UTC, unless given
     * @param int $keepFormerSlugs how many former slugs one tenant holds at a time; 0 holds none
     */
    public function __construct(
        private Connection $connection,
        private string $table = self::TABLE,
        ?\Closure $clock = null,
        private int $keepFormerSlugs = self::KEEP_FORMER_SLUGS,
    ) {
        if ($keepFormerSlugs < 0) {
            throw new \InvalidArgumentException('A tenant holds 0 or more former slugs.');
        }
        if (preg_match('/^[a-z_][a-z0-9_]{0,50}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name the tenants will be kept in.");
        }
        $this->timestamps = TimestampStorage::for($connection);
        $this->slugs = self::slugsTable($table);
        $this->clock = $clock ?? static fn (): CarbonImmutable => CarbonImmutable::now('UTC');
    }

    /** The slug history's table for a tenants table: `tenant_slugs` beside `tenants`, `{table}_slugs` beside another. */
    public static function slugsTable(string $table = self::TABLE): string
    {
        return $table === self::TABLE ? self::SLUGS_TABLE : "{$table}_slugs";
    }

    public static function define(Schema $schema, string $table = self::TABLE, bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);

        $schema->create($table, static function (Table $tenants) use ($table): void {
            $tenants->string('id', 191)->primary();
            $tenants->string('parent_id', 191)->nullable();
            $tenants->string('name', TenantSettings::MAX_NAME);
            $tenants->string('slug', 63)->unique();
            $tenants->timestamp('created_at');
            $tenants->index(['parent_id'], "{$table}_parent");
        }, $ifNotExists);
        self::defineSlugs($schema, $table, $ifNotExists);
    }

    /**
     * The slug history alone, for an application whose tenants table is
     * already there: every slug a tenant holds or held, one row each, its
     * slug the key -- what no two tenants can claim at once -- and when it
     * was let go, null while it is the tenant's slug.
     */
    public static function defineSlugs(Schema $schema, string $table = self::TABLE, bool $ifNotExists = false): void
    {
        $slugs = self::slugsTable($table);
        $schema->create($slugs, static function (Table $history) use ($slugs): void {
            $history->string('slug', 63)->primary();
            $history->string('tenant_id', 191);
            $history->timestamp('released_at')->nullable();
            $history->index(['tenant_id'], "{$slugs}_tenant");
        }, $ifNotExists);
    }

    /**
     * A new tenant, its slug already held to SlugRules.
     *
     * @throws SlugTaken
     */
    public function create(string $name, string $slug, ?string $parent = null, ?string $id = null): string
    {
        $id ??= Uuid::v7();
        $this->claiming($slug, function () use ($id, $parent, $name, $slug): void {
            $this->claim($id, $slug);
            $this->connection->execute(
                "INSERT INTO {$this->table} (id, parent_id, name, slug, created_at) VALUES (?, ?, ?, ?, ?)",
                [$id, $parent, $name, $slug, $this->timestamps->format(($this->clock)())],
            );
        });

        return $id;
    }

    public function profile(string $tenant): ?TenantProfile
    {
        $rows = $this->connection->select("SELECT id, name, slug FROM {$this->table} WHERE id = ?", [$tenant]);

        return $rows === [] ? null : new TenantProfile((string) $rows[0]['id'], (string) $rows[0]['name'], (string) $rows[0]['slug']);
    }

    public function rename(string $tenant, string $name): void
    {
        $this->connection->execute("UPDATE {$this->table} SET name = ? WHERE id = ?", [$name, $tenant]);
    }

    /**
     * Moves the tenant to $slug, keeping the slug it leaves reserved to it
     * for RESERVED_DAYS: in the slug history, as let go now.
     *
     * @throws SlugTaken when another tenant holds $slug, or held it within RESERVED_DAYS
     */
    public function changeSlug(string $tenant, string $slug): void
    {
        $this->claiming($slug, function () use ($tenant, $slug): void {
            $before = $this->profile($tenant)?->slug;
            if ($before === null || $before === $slug) {
                return;
            }
            $this->claim($tenant, $slug);
            $this->release($tenant, $before);
            $this->connection->execute("UPDATE {$this->table} SET slug = ? WHERE id = ?", [$slug, $tenant]);
        });
    }

    /**
     * The slug the tenant that left $slug within RESERVED_DAYS goes by now,
     * or null: where an old address of a tenant's sends its visitors.
     */
    public function movedFrom(string $slug): ?string
    {
        $rows = $this->connection->select(
            "SELECT t.slug FROM {$this->slugs} h JOIN {$this->table} t ON t.id = h.tenant_id "
            . 'WHERE h.slug = ? AND h.released_at IS NOT NULL AND h.released_at >= ? AND t.slug <> h.slug',
            [$slug, $this->timestamps->format($this->cutoff())],
        );
        $current = $rows[0]['slug'] ?? null;

        return \is_string($current) && $current !== '' ? $current : null;
    }

    /**
     * Claims $slug for $tenant in the history, by its unique key: a former
     * slug past its reservation is anyone's, and the tenant's own former
     * slug is its own to take back; any other row there refuses the insert.
     */
    private function claim(string $tenant, string $slug): void
    {
        $this->connection->execute(
            "DELETE FROM {$this->slugs} WHERE slug = ? AND ((released_at IS NOT NULL AND released_at < ?) OR tenant_id = ?)",
            [$slug, $this->timestamps->format($this->cutoff()), $tenant],
        );
        $this->connection->execute("INSERT INTO {$this->slugs} (slug, tenant_id, released_at) VALUES (?, ?, NULL)", [$slug, $tenant]);
    }

    /**
     * Marks $slug let go by $tenant now. A slug from before the history was
     * kept has no row yet: it is claimed as let go, in a savepoint of its
     * own so a row already there leaves the change able to go on.
     */
    private function release(string $tenant, string $slug): void
    {
        $now = $this->timestamps->format(($this->clock)());
        $released = $this->connection->execute(
            "UPDATE {$this->slugs} SET released_at = ? WHERE slug = ? AND tenant_id = ?",
            [$now, $slug, $tenant],
        );
        if ($released === 0) {
            $this->connection->execute("INSERT INTO {$this->slugs} (slug, tenant_id, released_at) VALUES (?, ?, ?)", [$slug, $tenant, $now]);
        }

        // The one just left, and the newest others up to the cap; the rest go.
        $kept = $this->keepFormerSlugs === 0 ? [] : [$slug, ...\array_slice(array_values(array_diff($this->formerSlugs($tenant), [$slug])), 0, $this->keepFormerSlugs - 1)];
        foreach (array_diff($this->formerSlugs($tenant), $kept) as $old) {
            $this->connection->execute("DELETE FROM {$this->slugs} WHERE slug = ? AND tenant_id = ? AND released_at IS NOT NULL", [$old, $tenant]);
        }
    }

    /**
     * The former slugs the tenant still holds, the most recently left
     * first: what staff tooling shows, and releaseSlug() lets go.
     *
     * @return list<string>
     */
    public function formerSlugs(string $tenant): array
    {
        $rows = $this->connection->select(
            "SELECT slug FROM {$this->slugs} WHERE tenant_id = ? AND released_at IS NOT NULL AND released_at >= ? ORDER BY released_at DESC, slug",
            [$tenant, $this->timestamps->format($this->cutoff())],
        );

        return array_map(static fn (array $row): string => (string) $row['slug'], $rows);
    }

    /**
     * Lets a former slug go now, for the platform's staff: anyone may take
     * it, and its old address sends nobody on. False for a slug no tenant
     * holds as a former one -- a slug a tenant goes by is never let go here.
     */
    public function releaseSlug(string $slug): bool
    {
        return $this->connection->execute("DELETE FROM {$this->slugs} WHERE slug = ? AND released_at IS NOT NULL", [$slug]) > 0;
    }

    private function cutoff(): CarbonImmutable
    {
        return ($this->clock)()->subDays(self::RESERVED_DAYS);
    }

    /**
     * $write, which gives a tenant $slug, in a transaction of its own -- a
     * savepoint inside the caller's, so a refusal leaves that one able to go
     * on -- with the unique index's refusal as SlugTaken. No read first: one
     * would race another tenant's write, and the index cannot.
     *
     * @param \Closure(): mixed $write
     * @throws SlugTaken
     */
    private function claiming(string $slug, \Closure $write): void
    {
        try {
            $this->connection->transaction($write);
        } catch (\PDOException $e) {
            throw UniqueViolation::is($e) ? new SlugTaken($slug, $e) : $e;
        }
    }

    public function tenantForSlug(string $slug): ?string
    {
        $rows = $this->connection->select("SELECT id FROM {$this->table} WHERE slug = ?", [$slug]);
        $id = $rows[0]['id'] ?? null;

        return \is_string($id) && $id !== '' ? $id : null;
    }

    /** The tenant's slug, or null: what SubdomainAddresses and Domains' custom domains read. */
    public function slugOf(string $tenant): ?string
    {
        return $this->profile($tenant)?->slug;
    }

    public function has(string $tenant): bool
    {
        return $this->profile($tenant) !== null;
    }

    public function all(): iterable
    {
        foreach ($this->connection->select("SELECT id FROM {$this->table} ORDER BY id") as $row) {
            yield (string) $row['id'];
        }
    }

    public function parentOf(string $tenant): ?string
    {
        $rows = $this->connection->select("SELECT parent_id FROM {$this->table} WHERE id = ?", [$tenant]);
        $parent = $rows[0]['parent_id'] ?? null;

        return \is_string($parent) && $parent !== '' ? $parent : null;
    }

    public function descendantsOf(string $tenant): array
    {
        $rows = $this->connection->select(
            "WITH RECURSIVE below(id) AS (SELECT id FROM {$this->table} WHERE parent_id = ? "
            . "UNION SELECT t.id FROM {$this->table} t JOIN below b ON t.parent_id = b.id) SELECT id FROM below ORDER BY id",
            [$tenant],
        );

        return array_values(array_filter(array_map(static fn (array $row): string => (string) $row['id'], $rows), static fn (string $id): bool => $id !== $tenant));
    }

    /**
     * One recursive query per page: the subtree walked by parent_id, its
     * ids compared and ordered byte by byte -- Postgres's "C" collation,
     * MySQL's binary cast, SQLite's own -- whatever the table's collation,
     * so a page goes on exactly after the last (ADR 0196).
     */
    public function descendantsAfter(string $tenant, ?string $after, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('A page of a subtree holds a tenant at least.');
        }
        [$bytes, $cursor] = match ($this->connection instanceof NamesDriver ? $this->connection->driver() : 'sqlite') {
            'pgsql' => ['id COLLATE "C"', '?'],
            'mysql' => ['CAST(id AS BINARY)', 'CAST(? AS BINARY)'],
            default => ['id', '?'],
        };
        $rows = $this->connection->select(
            "WITH RECURSIVE below(id) AS (SELECT id FROM {$this->table} WHERE parent_id = ? "
            . "UNION SELECT t.id FROM {$this->table} t JOIN below b ON t.parent_id = b.id) "
            . 'SELECT id FROM below WHERE id <> ?' . ($after === null ? '' : " AND {$bytes} > {$cursor}")
            . " ORDER BY {$bytes} LIMIT " . $limit,
            $after === null ? [$tenant, $tenant] : [$tenant, $tenant, $after],
        );

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }
}
