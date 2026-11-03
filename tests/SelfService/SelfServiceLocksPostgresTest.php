<?php

declare(strict_types=1);

/**
 * The self-service rules that count what is there -- a tenant's custom
 * domains against the limit, the members left holding each permission --
 * hold under two sessions at once on Postgres (ADR 0161): each counts under
 * a lock the other waits on until the first commits, where under READ
 * COMMITTED neither saw the other's uncommitted write and both passed.
 */

namespace Tinvar\Tests\Tenancy\SelfService\Locks;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tenancy\SelfService\DatabaseTenantMembers;
use Tinvar\Tenancy\SelfService\TenantDomains;
use Tinvar\Tenancy\SelfService\TenantSettings;
use Tinvar\Tenancy\Tenancy;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdminWorld;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\People;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\Person;
use Tinvar\Validation\ValidationException;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/databases.php';
require_once __DIR__ . '/Fixtures/self_service.php';

/**
 * A world on one session, and an ORM on a second session to the same
 * schema: another worker, asking at the same moment. The second gives up
 * waiting on a lock after a moment, rather than hang the test.
 *
 * @param \Closure(AdminWorld, Orm): void $test
 */
function twoSessions(int $limit, \Closure $test): void
{
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema) use ($limit, $test): void {
        $pdo->exec('CREATE TABLE notes (id TEXT PRIMARY KEY, body TEXT NOT NULL, tenant_id TEXT NOT NULL)');
        $world = new AdminWorld(new PdoConnection($pdo, 'locks-a'), limit: $limit);

        $other = Databases::another('pgsql');
        $other->exec("SET search_path TO {$schema}");
        $other->exec("SET lock_timeout = '300ms'");
        try {
            $test($world, new Orm(new PdoConnection($other, 'locks-b'), new IdentityMap('locks-b'), Tenancy::tree()));
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }
    });
}

it('counts a tenant\'s custom domains under a lock, so two adds at once cannot both pass the limit', function () {
    twoSessions(1, static function (AdminWorld $world, Orm $other): void {
        $elsewhere = new TenantDomains($other);

        $world->orm->statements()->transaction(static function () use ($world, $elsewhere): void {
            $world->kit->claims?->add('acme', 'one.example.org', null);

            // The first add has not committed: the second waits on it, and
            // here gives up waiting, rather than count without it.
            expect(static fn () => $elsewhere->addWithin('acme', 'two.example.org', 1))->toThrow(\PDOException::class);
        });

        // Committed, the first is counted.
        expect($elsewhere->addWithin('acme', 'two.example.org', 1))->toBeNull()
            ->and(array_map(static fn ($d): string => $d->host, $world->domains->forTenant('acme')))->toBe(['one.example.org']);
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('holds a tenant\'s members while a change is checked, so two owners cannot each step the other down', function () {
    twoSessions(3, static function (AdminWorld $world, Orm $other): void {
        $world->members->changeRole('acme', 'customer', 'm-2', 'owner');
        // Bob's request, on another worker: its own session, its own settings.
        $elsewhere = new TenantSettings(
            $world->tenants,
            new DatabaseTenantMembers($other, new People()),
            $world->kit->invitations,
            $world->kit->invitePolicy,
            $world->invites,
        );

        $world->orm->statements()->transaction(static function () use ($world, $elsewhere): void {
            // Ann steps Bob down; Bob, at the same moment, steps Ann down.
            // Each alone leaves an owner; both at once would leave none.
            $world->kit->settings->changeRole('acme', 'customer', 'm-2', 'admin', new Person('m-1', 'ann@example.test', 'Ann'));

            // Bob's check waits on Ann's change, and here gives up waiting,
            // rather than read the members as they were before it.
            expect(static fn () => $elsewhere->changeRole('acme', 'customer', 'm-1', 'admin', new Person('m-2', 'bob@example.test', 'Bob')))
                ->toThrow(\PDOException::class);
        });

        // Committed, Bob is an admin, and may no longer touch the owner.
        expect(static fn () => $elsewhere->changeRole('acme', 'customer', 'm-1', 'admin', new Person('m-2', 'bob@example.test', 'Bob')))
            ->toThrow(ValidationException::class);

        expect($world->members->roleOf('acme', 'customer', 'm-1'))->toBe('owner')
            ->and($world->members->roleOf('acme', 'customer', 'm-2'))->toBe('admin');
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');
