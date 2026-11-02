<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Carbon\CarbonImmutable;
use Tinvar\Accounts\AccountUser;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Membership;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\TenantMemberships;
use Tinvar\Auth\UserLookup;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Orm\TimestampStorage;

/**
 * Who belongs to which tenant's panel, and in what role, in a table of
 * tinvar/tenancy's own (ADR 0159): the settings page's TenantMembers, and
 * the framework's Memberships and TenantMemberships from the same rows --
 * so the member the page lists is the member every panel lets in, with the
 * permissions their role grants (TenantRoles).
 *
 * Every read and write names its tenant and panel. A row whose role no
 * case of the application's enum has grants nothing, and is no membership.
 */
final readonly class DatabaseTenantMembers implements TenantMembers, Memberships, TenantMemberships
{
    public const string TABLE = 'tenant_members';

    private TimestampStorage $timestamps;

    /**
     * @param UserLookup $users who each member is, for their name and address: an
     *        AccountUser's, or their id alone
     * @param \Closure(string): (\UnitEnum|string)|null $kindOf a tenant's kind, which
     *        #[Panel(requires:)] is held to; '' for every tenant unless given
     */
    public function __construct(
        private Connection $connection,
        private UserLookup $users,
        private TenantRoles $roles = new TenantRoles(),
        private string $table = self::TABLE,
        private ?\Closure $kindOf = null,
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name the members will be kept in.");
        }
        $this->timestamps = TimestampStorage::for($connection);
    }

    /**
     * The table, its users a foreign key to the application's user model, so
     * a user deleted takes their memberships with them.
     *
     * @param class-string<\Tinvar\Orm\Model> $userModel
     */
    public static function define(Schema $schema, string $userModel, string $table = self::TABLE, bool $ifNotExists = false): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name the members will be kept in.");
        }

        $schema->create($table, static function (Table $members) use ($table, $userModel): void {
            $members->tenant();
            $members->foreign($userModel, 'user_id')->cascadeOnDelete();
            $members->string('panel', 64);
            $members->string('role', 64);
            $members->timestamp('created_at');
            $members->primary(['tenant_id', 'panel', 'user_id']);
            $members->index(['user_id', 'panel'], "{$table}_user");
        }, $ifNotExists);
    }

    public function members(string $tenant, string $panel): array
    {
        $rows = $this->connection->select(
            "SELECT user_id, role FROM {$this->table} WHERE tenant_id = ? AND panel = ? ORDER BY created_at, user_id",
            [$tenant, $panel],
        );

        return array_map(function (array $row): TenantMember {
            $id = (string) $row['user_id'];
            $user = $this->users->find($id);

            return new TenantMember(
                $id,
                $user instanceof AccountUser ? $user->accountName() : $id,
                $user instanceof AccountUser ? $user->accountEmail() : '',
                (string) $row['role'],
            );
        }, $rows);
    }

    public function roleOf(string $tenant, string $panel, string $userId): ?string
    {
        $rows = $this->connection->select(
            "SELECT role FROM {$this->table} WHERE tenant_id = ? AND panel = ? AND user_id = ?",
            [$tenant, $panel, $userId],
        );
        $role = $rows[0]['role'] ?? null;

        return \is_string($role) ? $role : null;
    }

    public function grant(string $tenant, string $panel, string $userId, string $role): void
    {
        if ($tenant === '' || $this->roleOf($tenant, $panel, $userId) !== null) {
            return;
        }
        $this->connection->execute(
            "INSERT INTO {$this->table} (tenant_id, user_id, panel, role, created_at) VALUES (?, ?, ?, ?, ?)",
            [$tenant, $userId, $panel, $role, $this->timestamps->format(CarbonImmutable::now('UTC'))],
        );
    }

    public function changeRole(string $tenant, string $panel, string $userId, string $role): void
    {
        $this->connection->execute(
            "UPDATE {$this->table} SET role = ? WHERE tenant_id = ? AND panel = ? AND user_id = ?",
            [$role, $tenant, $panel, $userId],
        );
    }

    public function remove(string $tenant, string $panel, string $userId): void
    {
        $this->connection->execute("DELETE FROM {$this->table} WHERE tenant_id = ? AND panel = ? AND user_id = ?", [$tenant, $panel, $userId]);
    }

    public function membership(Authenticatable $user, string $panel, string $tenant): ?Membership
    {
        if ($tenant === '') {
            return null;
        }
        $role = $this->roles->of($this->roleOf($tenant, $panel, $user->authIdentifier()));
        if ($role === null) {
            return null;
        }

        return new Membership($this->kindOf === null ? '' : ($this->kindOf)($tenant), $role->permissions());
    }

    public function tenantsOf(Authenticatable $user, string $panel): array
    {
        $rows = $this->connection->select(
            "SELECT tenant_id FROM {$this->table} WHERE user_id = ? AND panel = ? ORDER BY created_at, tenant_id",
            [$user->authIdentifier(), $panel],
        );

        return array_map(static fn (array $row): string => (string) $row['tenant_id'], $rows);
    }
}
