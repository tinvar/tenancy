<?php

declare(strict_types=1);

/**
 * The self-service pages' stores on Postgres (ADR 0161), where their SQL --
 * a recursive tenant tree, a unique index that is all that keeps two
 * tenants off one host, a savepoint around a refused activation -- must
 * hold as it does on SQLite, and every read of another tenant's rows still
 * finds nothing.
 */

namespace Tinvar\Tests\Tenancy\SelfService\Postgres;

use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdminWorld;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/databases.php';
require_once __DIR__ . '/Fixtures/self_service.php';

/** @param \Closure(AdminWorld, \PDO): void $test */
function onPostgres(\Closure $test): void
{
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo) use ($test): void {
        $pdo->exec('CREATE TABLE notes (id TEXT PRIMARY KEY, body TEXT NOT NULL, tenant_id TEXT NOT NULL)');
        $test(new AdminWorld(new PdoConnection($pdo, 'self-service-pgsql')), $pdo);
    });
}

it('keeps each tenant\'s domains to itself, and one host to one tenant', function () {
    onPostgres(static function (AdminWorld $world, \PDO $pdo): void {
        $world->act('OrganizationDomains.add', ['host' => 'brand.example.org'], 'm-9', 'globex.example.test');
        $world->act('OrganizationDomains.add', ['host' => 'brand.example.org']);
        $globex = $world->domains->forTenant('globex')[0];
        $acme = $world->domains->forTenant('acme')[0];

        // Both proved at once -- each its own token -- and globex first.
        $world->dns->aliases['brand.example.org'] = 'domains.example.test';
        $world->dns->texts['_tinvar-challenge.brand.example.org'] = ['tinvar-domain=' . $globex->token, 'tinvar-domain=' . $acme->token];
        $world->act('OrganizationDomains.check', ['domain' => $globex->id], 'm-9', 'globex.example.test');
        $second = $world->act('OrganizationDomains.check', ['domain' => $acme->id]);

        expect($second->status)->toBe(200, $second->body())
            ->and($world->domains->tenantForHost('brand.example.org'))->toBe('globex')
            ->and($world->domains->ofTenant('acme', $acme->id)?->status->value)->toBe('pending')
            ->and($world->domains->ofTenant('acme', $globex->id))->toBeNull()
            ->and($world->act('OrganizationDomains.remove', ['domain' => $globex->id])->status)->toBe(404);

        // And the database holds it, whatever the code checked.
        expect(static fn () => $pdo->prepare('UPDATE tenant_domains SET held_host = host WHERE id = ?')->execute([$acme->id]))
            ->toThrow(\PDOException::class);
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('walks the tenant tree, and keeps slugs unique', function () {
    onPostgres(static function (AdminWorld $world): void {
        $world->tenants->create('Acme EU', 'acme-eu', 'acme', 'acme-eu');
        $world->tenants->create('Acme EU Paris', 'acme-paris', 'acme-eu', 'acme-paris');

        expect($world->tenants->descendantsOf('acme'))->toBe(['acme-eu', 'acme-paris'])
            ->and($world->tenants->parentOf('acme-paris'))->toBe('acme-eu')
            ->and($world->act('OrganizationSettings.changeSlug', ['slug' => 'acme-eu'])->status)->toBe(422);
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('finds no member of another tenant, and records each change', function () {
    onPostgres(static function (AdminWorld $world): void {
        expect($world->act('OrganizationSettings.removeMember', ['member' => 'm-9'])->status)->toBe(404)
            ->and($world->act('OrganizationSettings.changeRole', ['member' => 'm-3', 'role' => 'admin'])->status)->toBe(200)
            ->and($world->members->roleOf('acme', 'customer', 'm-3'))->toBe('admin')
            ->and($world->members->tenantsOf(new \Tinvar\Tests\Tenancy\SelfService\Fixtures\Person('m-1', '', ''), 'customer'))->toBe(['acme', 'globex']);
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');
