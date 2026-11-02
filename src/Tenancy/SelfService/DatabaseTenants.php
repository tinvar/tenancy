<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Orm\TimestampStorage;
use Tinvar\Support\Uuid;
use Tinvar\Tenancy\TenantDirectory;
use Tinvar\Tenancy\TenantTree;

/**
 * The tenants, in a table of tinvar/tenancy's own (ADR 0161): an id, a
 * name, a slug -- its subdomain's label -- and a parent. A ready-made
 * TenantProfiles for the settings page, and the TenantDirectory and
 * TenantTree the rest of tenancy reads, from the one table, so they cannot
 * disagree. An application with its own tenants table implements those
 * over it instead, and never makes this one.
 *
 * The slug is unique by the database: two tenants taking one at once
 * cannot both have it.
 */
final readonly class DatabaseTenants implements TenantProfiles, TenantDirectory, TenantTree
{
    public const string TABLE = 'tenants';

    private TimestampStorage $timestamps;

    public function __construct(private Connection $connection, private string $table = self::TABLE)
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name the tenants will be kept in.");
        }
        $this->timestamps = TimestampStorage::for($connection);
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
    }

    /**
     * A new tenant, its slug already held to SlugRules.
     *
     * @throws SlugTaken
     */
    public function create(string $name, string $slug, ?string $parent = null, ?string $id = null): string
    {
        $id ??= Uuid::v7();
        if ($this->tenantForSlug($slug) !== null) {
            throw new SlugTaken($slug);
        }
        $this->connection->execute(
            "INSERT INTO {$this->table} (id, parent_id, name, slug, created_at) VALUES (?, ?, ?, ?, ?)",
            [$id, $parent, $name, $slug, $this->timestamps->format(CarbonImmutable::now('UTC'))],
        );

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

    public function changeSlug(string $tenant, string $slug): void
    {
        $holder = $this->tenantForSlug($slug);
        if ($holder !== null && $holder !== $tenant) {
            throw new SlugTaken($slug);
        }
        try {
            $this->connection->transaction(fn (): int => $this->connection->execute("UPDATE {$this->table} SET slug = ? WHERE id = ?", [$slug, $tenant]));
        } catch (\PDOException) {
            // Taken in between: the unique index refused it.
            throw new SlugTaken($slug);
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
}
