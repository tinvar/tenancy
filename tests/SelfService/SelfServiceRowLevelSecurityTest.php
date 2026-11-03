<?php

declare(strict_types=1);

/**
 * The self-service stores under the row-level security their own tables
 * get on Postgres (Table::tenant() adds the FORCEd policy, ADR 0026/0029),
 * run as the application's role rather than a superuser, which bypasses
 * every policy and so sees nothing of this.
 *
 * The cross-tenant reads the kit relies on -- which tenant holds a host
 * (the realm lookup, Caddy's TLS ask, the daily re-verification) and which
 * tenants a user belongs to (the switcher) -- are made with no tenant in
 * force, or as one tenant, and the policy then hides every other tenant's
 * rows from them.
 */

namespace Tinvar\Tests\Tenancy\SelfService\RowLevelSecurity;

use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\RowLevelSecurity;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdminWorld;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\Person;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/databases.php';
require_once __DIR__ . '/Fixtures/self_service.php';

/**
 * The world built by the superuser the tests connect as, then every
 * statement run as a plain role the policies hold, as the application's
 * role is in production.
 *
 * @param \Closure(AdminWorld, \PDO): void $test
 */
function asApplicationRole(\Closure $test): void
{
    $role = 'tinvar_rls_audit_' . bin2hex(random_bytes(4));
    $pdo = null;
    try {
        Databases::inScratchSchema('pgsql', static function (\PDO $connection, string $schema) use ($test, $role, &$pdo): void {
            $pdo = $connection;
            $pdo->exec('CREATE TABLE notes (id TEXT PRIMARY KEY, body TEXT NOT NULL, tenant_id TEXT NOT NULL)');
            $world = new AdminWorld(new PdoConnection($pdo, 'self-service-rls'));
            // The fixture makes tenant_members by hand; DatabaseTenantMembers::define() makes it with Table::tenant(), which adds this policy.
            foreach (RowLevelSecurity::policy('tenant_members') as $sql) {
                $pdo->exec($sql);
            }
            $pdo->exec("CREATE ROLE {$role} NOLOGIN");
            $pdo->exec("GRANT USAGE ON SCHEMA {$schema} TO {$role}");
            $pdo->exec("GRANT ALL ON ALL TABLES IN SCHEMA {$schema} TO {$role}");
            $pdo->exec("SET ROLE {$role}");
            try {
                $test($world, $pdo);
            } finally {
                $pdo->exec('RESET ROLE');
            }
        });
    } finally {
        $pdo?->exec("DROP ROLE IF EXISTS {$role}");
    }
}

it('is held by the policy at all: a superuser is not, so this proves the role is', function () {
    asApplicationRole(static function (AdminWorld $world, \PDO $pdo): void {
        expect($pdo->query('SELECT current_setting(\'is_superuser\')')->fetchColumn())->toBe('off')
            // The policy hides every tenant's members from a statement run with no tenant in force.
            ->and($world->orm->statements()->select('SELECT COUNT(*) AS n FROM tenant_members')[0]['n'] ?? null)->toEqual(0)
            ->and($world->orm->actAs('acme', static fn (): mixed => $world->orm->statements()->select('SELECT COUNT(*) AS n FROM tenant_members')[0]['n'] ?? null))->toEqual(3);
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('still finds which tenant holds a verified custom domain, with no tenant in force', function () {
    asApplicationRole(static function (AdminWorld $world): void {
        $added = $world->act('OrganizationDomains.add', ['host' => 'shop.acme.org']);
        expect($added->status)->toBe(200, $added->body());
        $domain = $world->orm->actAs('acme', static fn (): ?\Tinvar\Tenancy\SelfService\TenantDomain => $world->domains->forTenant('acme')[0] ?? null);
        expect($domain)->not->toBeNull();
        $world->dns->prove('shop.acme.org', $domain->token);
        $checked = $world->act('OrganizationDomains.check', ['domain' => $domain->id]);
        expect($checked->status)->toBe(200, $checked->body())
            ->and($world->orm->actAs('acme', static fn (): ?string => $world->domains->ofTenant('acme', $domain->id)?->status->value))->toBe('verified');

        // The realm lookup, Caddy's TLS ask and the daily re-verification all
        // read by held_host with no tenant in force.
        expect($world->domains->tenantForHost('shop.acme.org'))->toBe('acme')
            ->and($world->domains->find('shop.acme.org')?->active)->toBeTrue()
            ->and($world->domains->due(AdminWorld::$now->addDay(), 10))->toHaveCount(1);
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('still lists every tenant a user belongs to on the switcher, from a host of one of them', function () {
    asApplicationRole(static function (AdminWorld $world): void {
        $page = $world->page('/organizations', 'm-1', 'acme.example.test');

        expect(array_column($page['tenants'], 'tenant'))->toBe(['acme', 'globex']);
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('still answers tenantsOf() across tenants while one is in force', function () {
    asApplicationRole(static function (AdminWorld $world): void {
        $ann = new Person('m-1', '', '');

        expect($world->orm->actAs('acme', fn (): array => $world->members->tenantsOf($ann, 'customer')))->toBe(['acme', 'globex']);
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('still takes a domain out of service by its host, and reads another tenant\'s address, under the policy', function () {
    asApplicationRole(static function (AdminWorld $world): void {
        expect($world->act('OrganizationDomains.add', ['host' => 'shop.acme.org'])->status)->toBe(200);
        $domain = $world->domains->forTenant('acme')[0];
        $world->dns->prove('shop.acme.org', $domain->token);
        expect($world->act('OrganizationDomains.check', ['domain' => $domain->id])->status)->toBe(200);

        // The switcher, as globex, asks for acme's address.
        expect($world->orm->actAs('globex', static fn (): ?string => $world->domains->primaryFor('acme')))->toBe('shop.acme.org');

        // The daily re-verification, with no tenant in force, finds its DNS moved.
        $world->domains->deactivate('shop.acme.org', 'It no longer points at the platform.', AdminWorld::$now->addDay());

        expect($world->domains->tenantForHost('shop.acme.org'))->toBeNull()
            ->and($world->domains->ofTenant('acme', $domain->id)?->status->value)->toBe('deactivated');
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');
