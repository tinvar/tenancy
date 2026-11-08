<?php

declare(strict_types=1);

/**
 * The tenant tree a page at a time (ADR 0196): DatabaseTenants walks its
 * table one recursive query per page, in byte order of the tenants' ids,
 * on SQLite, Postgres and MySQL alike; TenantHierarchy hands the Orm those
 * pages, or pages a tree that answers descendantsOf() alone; and a tenant
 * moved inside a transaction is where the walk finds it in that
 * transaction, since the walk reads the one record the move changes.
 */

namespace Tinvar\Tests\Tenancy\TreePaging;

use Tinvar\Orm\Connection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PagedTenantSubtrees;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\SubtreePage;
use Tinvar\Orm\TenantBounds;
use Tinvar\Tenancy\PagedTenantTree;
use Tinvar\Tenancy\SelfService\DatabaseTenants;
use Tinvar\Tenancy\Tenancy;
use Tinvar\Tenancy\TenantHierarchy;
use Tinvar\Tenancy\TenantTree;
use Tinvar\Tests\Orm\Fixtures\Databases;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/databases.php';

/**
 * A reseller over customers whose ids sort by byte and not by letter --
 * capitals before lower case -- with a site each, and a tenant beside:
 *
 *     reseller            other
 *     ├── Zeta ── Zeta-1
 *     ├── acme ── acme-1
 *     └── beta ── beta-1
 */
function planted(Connection $connection): DatabaseTenants
{
    DatabaseTenants::define(new Schema($connection, Dialect::for($connection)));
    $tenants = new DatabaseTenants($connection);
    foreach ([['reseller', null], ['other', null], ['Zeta', 'reseller'], ['acme', 'reseller'], ['beta', 'reseller'], ['Zeta-1', 'Zeta'], ['acme-1', 'acme'], ['beta-1', 'beta']] as [$id, $parent]) {
        $tenants->create(ucfirst($id), strtolower($id), $parent, $id);
    }

    return $tenants;
}

/** @return list<list<string>> */
function pagesOf(PagedTenantSubtrees $tree, string $root, int $size, ?string $after = null): array
{
    return array_map(static fn (SubtreePage $page): array => $page->tenants, iterator_to_array(new TenantBounds($tree)->pages($root, $size, $after), false));
}

function pagesAlike(DatabaseTenants $tenants): void
{
    expect($tenants)->toBeInstanceOf(PagedTenantTree::class)
        ->and($tenants->descendantsAfter('reseller', null, 10))->toBe(['Zeta', 'Zeta-1', 'acme', 'acme-1', 'beta', 'beta-1'])
        ->and($tenants->descendantsAfter('reseller', 'Zeta-1', 2))->toBe(['acme', 'acme-1'])
        ->and($tenants->descendantsAfter('reseller', 'beta-1', 2))->toBe([])
        ->and($tenants->descendantsAfter('acme', null, 5))->toBe(['acme-1'])
        ->and($tenants->descendantsAfter('nobody', null, 5))->toBe([])
        ->and(pagesOf(Tenancy::tree($tenants), 'reseller', 3))->toBe([['Zeta', 'Zeta-1', 'acme'], ['acme-1', 'beta', 'beta-1'], ['reseller']])
        ->and(pagesOf(Tenancy::tree($tenants), 'reseller', 4, after: 'acme-1'))->toBe([['beta', 'beta-1', 'reseller']]);
}

it('pages a subtree in byte order of its ids, on SQLite', function () {
    pagesAlike(planted(PdoConnection::sqlite(':memory:', 'paging')));
});

it('pages a subtree in byte order of its ids, on Postgres', function () {
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo): void {
        pagesAlike(planted(new PdoConnection($pdo, 'paging-pgsql')));
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('pages a subtree in byte order of its ids, on MySQL', function () {
    Databases::inScratchSchema('mysql', static function (\PDO $pdo): void {
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
        pagesAlike(planted(new PdoConnection($pdo, 'paging-mysql')));
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

it('pages a tree that answers descendantsOf() alone, as the Orm asks', function () {
    $tree = new class implements TenantTree {
        public function parentOf(string $tenant): ?string
        {
            return ['b' => 'root', 'a' => 'root', 'c' => 'a'][$tenant] ?? null;
        }

        public function descendantsOf(string $tenant): array
        {
            return $tenant === 'root' ? ['b', 'a', 'c', 'root', ''] : [];
        }
    };
    $hierarchy = new TenantHierarchy($tree);

    expect($hierarchy)->toBeInstanceOf(PagedTenantSubtrees::class)
        ->and($hierarchy->descendantsAfter('root', null, 2))->toBe(['a', 'b'])
        ->and($hierarchy->descendantsAfter('root', 'a', 5))->toBe(['b', 'c'])
        ->and(pagesOf($hierarchy, 'root', 3))->toBe([['a', 'b', 'c'], ['root']]);
});

it('walks a tenant moved inside a transaction where the transaction has it, and back where it was after a rollback', function () {
    $connection = PdoConnection::sqlite(':memory:', 'paging');
    $tenants = planted($connection);
    $orm = new Orm($connection, new IdentityMap('paging'), Tenancy::tree($tenants));
    $walk = static function () use ($orm): array {
        $seen = [];
        $orm->forEachTenant('reseller', static function (string $tenant) use (&$seen): void {
            $seen[] = $tenant;
        }, pageSize: 2);

        return $seen;
    };

    $during = null;
    try {
        $connection->transaction(static function () use ($connection, $walk, &$during): void {
            // acme, and its site with it, moved to other.
            $connection->execute('UPDATE tenants SET parent_id = ? WHERE id = ?', ['other', 'acme']);
            $during = $walk();

            throw new \RuntimeException('rolled back');
        });
    } catch (\RuntimeException) {
    }

    expect($during)->toBe(['Zeta', 'Zeta-1', 'beta', 'beta-1', 'reseller'])
        ->and($walk())->toBe(['Zeta', 'Zeta-1', 'acme', 'acme-1', 'beta', 'beta-1', 'reseller']);
});
