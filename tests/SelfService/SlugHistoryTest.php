<?php

declare(strict_types=1);

/**
 * A slug a tenant leaves stays its own for 90 days (ADR 0161): no other
 * tenant takes it, by sign-up or by a change, and its old address sends
 * visitors on to the new one. After that it is anyone's, and the old
 * address nobody's.
 */

namespace Tinvar\Tests\Tenancy\SelfService\SlugHistory;

use Carbon\CarbonImmutable;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Tenancy\SelfService\DatabaseTenants;
use Tinvar\Tenancy\SelfService\SlugTaken;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdminWorld;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/databases.php';
require_once __DIR__ . '/Fixtures/self_service.php';

final class Clock
{
    public static CarbonImmutable $now;
}

/** @param \Closure(DatabaseTenants): void $test */
function onDriver(string $driver, \Closure $test): void
{
    Clock::$now = CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC');
    $run = static function (\PDO $pdo) use ($test): void {
        $connection = new PdoConnection($pdo, 'slug-history');
        DatabaseTenants::define(new Schema($connection, Dialect::for($connection)));
        $test(new DatabaseTenants($connection, clock: static fn (): CarbonImmutable => Clock::$now));
    };

    $driver === 'sqlite' ? $run(new \PDO('sqlite::memory:')) : Databases::inScratchSchema($driver, $run);
}

function missing(string $driver): ?string
{
    return match ($driver) {
        'pgsql' => Databases::postgresMissing(),
        'mysql' => Databases::mysqlMissing(),
        default => null,
    };
}

it('keeps a slug a tenant left its own for 90 days, then lets it go', function (string $driver) {
    if (($missing = missing($driver)) !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, static function (DatabaseTenants $tenants): void {
        $tenants->create('Acme', 'acme', id: 'acme');
        $tenants->create('Globex', 'globex', id: 'globex');
        $tenants->changeSlug('acme', 'acme-co');

        // Nobody else takes it, by a change or a sign-up.
        expect(static fn () => $tenants->changeSlug('globex', 'acme'))->toThrow(SlugTaken::class)
            ->and(static fn () => $tenants->create('Acme Rival', 'acme'))->toThrow(SlugTaken::class)
            ->and($tenants->tenantForSlug('acme'))->toBeNull()
            ->and($tenants->movedFrom('acme'))->toBe('acme-co')
            ->and($tenants->movedFrom('acme-co'))->toBeNull()
            ->and($tenants->slugOf('globex'))->toBe('globex');

        // Moved again, both old slugs lead to the newest.
        Clock::$now = Clock::$now->addDays(30);
        $tenants->changeSlug('acme', 'acme-inc');
        expect($tenants->movedFrom('acme'))->toBe('acme-inc')
            ->and($tenants->movedFrom('acme-co'))->toBe('acme-inc');

        // 90 days after it was left, `acme` is anyone's; `acme-co` is still held.
        Clock::$now = Clock::$now->addDays(61);
        expect($tenants->movedFrom('acme'))->toBeNull()
            ->and(static fn () => $tenants->changeSlug('globex', 'acme-co'))->toThrow(SlugTaken::class);
        $tenants->changeSlug('globex', 'acme');
        expect($tenants->tenantForSlug('acme'))->toBe('globex')
            ->and($tenants->movedFrom('globex'))->toBe('acme');
    });
})->with(['sqlite', 'pgsql', 'mysql']);

it('lets a tenant take back a slug it left', function (string $driver) {
    if (($missing = missing($driver)) !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, static function (DatabaseTenants $tenants): void {
        $tenants->create('Acme', 'acme', id: 'acme');
        $tenants->changeSlug('acme', 'acme-co');
        $tenants->changeSlug('acme', 'acme');

        expect($tenants->tenantForSlug('acme'))->toBe('acme')
            ->and($tenants->movedFrom('acme'))->toBeNull()
            ->and($tenants->movedFrom('acme-co'))->toBe('acme');
    });
})->with(['sqlite', 'pgsql', 'mysql']);

it('holds only a tenant\'s last three former slugs, so renaming cannot squat on more', function (string $driver) {
    if (($missing = missing($driver)) !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, static function (DatabaseTenants $tenants): void {
        $tenants->create('Acme', 'acme', id: 'acme');
        $tenants->create('Globex', 'globex', id: 'globex');
        foreach (['acme-1', 'acme-2', 'acme-3', 'acme-4'] as $i => $slug) {
            Clock::$now = Clock::$now->addMinutes($i + 1);
            $tenants->changeSlug('acme', $slug);
        }

        // Left: acme, acme-1, acme-2, acme-3. Held: the last three alone.
        expect($tenants->formerSlugs('acme'))->toBe(['acme-3', 'acme-2', 'acme-1'])
            ->and($tenants->movedFrom('acme'))->toBeNull()
            ->and(static fn () => $tenants->changeSlug('globex', 'acme-1'))->toThrow(SlugTaken::class);
        $tenants->changeSlug('globex', 'acme');
        expect($tenants->tenantForSlug('acme'))->toBe('globex');
    });
})->with(['sqlite', 'pgsql', 'mysql']);

it('keeps as many former slugs as it is told to', function () {
    Clock::$now = CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC');
    $connection = new PdoConnection(new \PDO('sqlite::memory:'), 'slug-history');
    DatabaseTenants::define(new Schema($connection, Dialect::for($connection)));
    $tenants = new DatabaseTenants($connection, clock: static fn (): CarbonImmutable => Clock::$now, keepFormerSlugs: 1);
    $tenants->create('Acme', 'acme', id: 'acme');
    $tenants->changeSlug('acme', 'acme-1');
    Clock::$now = Clock::$now->addMinute();
    $tenants->changeSlug('acme', 'acme-2');

    expect($tenants->formerSlugs('acme'))->toBe(['acme-1'])
        ->and(static fn () => new DatabaseTenants($connection, keepFormerSlugs: -1))->toThrow(\InvalidArgumentException::class);
});

it('lets the platform release a reserved slug, and never one in use', function (string $driver) {
    if (($missing = missing($driver)) !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, static function (DatabaseTenants $tenants): void {
        $tenants->create('Acme', 'acme', id: 'acme');
        $tenants->create('Globex', 'globex', id: 'globex');
        $tenants->changeSlug('acme', 'acme-co');

        expect($tenants->releaseSlug('acme-co'))->toBeFalse()
            ->and($tenants->releaseSlug('nobody'))->toBeFalse()
            ->and($tenants->tenantForSlug('acme-co'))->toBe('acme')
            ->and($tenants->releaseSlug('acme'))->toBeTrue()
            ->and($tenants->movedFrom('acme'))->toBeNull()
            ->and($tenants->formerSlugs('acme'))->toBe([]);
        $tenants->changeSlug('globex', 'acme');
        expect($tenants->tenantForSlug('acme'))->toBe('globex');
    });
})->with(['sqlite', 'pgsql', 'mysql']);

it('reserves the slug of a tenant made before the history was kept, once it moves', function () {
    onDriver('sqlite', static function (DatabaseTenants $tenants): void {
        $tenants->create('Acme', 'acme', id: 'acme');
        $tenants->create('Globex', 'globex', id: 'globex');
        // As a tenants table from before: no history rows.
        (new \ReflectionProperty($tenants, 'connection'))->getValue($tenants)->execute('DELETE FROM tenant_slugs');

        $tenants->changeSlug('acme', 'acme-co');
        expect(static fn () => $tenants->changeSlug('globex', 'acme'))->toThrow(SlugTaken::class)
            ->and(static fn () => $tenants->changeSlug('globex', 'acme-co'))->toThrow(SlugTaken::class)
            ->and($tenants->movedFrom('acme'))->toBe('acme-co');
    });
});

it('sends a visitor to an old address on to the tenant\'s new one, path and query kept', function () {
    $world = new AdminWorld();
    expect($world->act('OrganizationSettings.changeSlug', ['slug' => 'acme-co'])->status)->toBe(303);
    $world->app->directory->slugs = ['acme-co' => 'acme', 'globex' => 'globex'];
    $world->app->directory->movedFrom = $world->tenants->movedFrom(...);

    $response = $world->app->get('/settings?tab=members', 'acme.example.test', ['x-user' => 'm-1']);
    expect($response->status)->toBe(308, $response->body())
        ->and($response->header('location'))->toBe('//acme-co.example.test/settings?tab=members')
        ->and($world->status('/settings', 'm-1', 'acme-co.example.test'))->toBe(200)
        // An address nobody ever held is still nobody's.
        ->and($world->status('/settings', 'm-1', 'nobody.example.test'))->toBe(404)
        // And globex may not take the slug acme left.
        ->and($world->act('OrganizationSettings.changeSlug', ['slug' => 'acme'], 'm-9', 'globex.example.test')->status)->toBe(422);
});

it('moves a visitor one label under the platform\'s own domain, and nowhere else', function () {
    $hosts = static fn (string $now): \Tinvar\Tenancy\Subdomains => new \Tinvar\Tenancy\Subdomains(
        ['example.test'],
        static fn (string $label): ?string => null,
        static fn (string $label): ?string => $label === 'acme' ? $now : null,
    );

    expect(static fn () => $hosts('acme-co')->tenantForHost('acme.example.test'))->toThrow(\Tinvar\Tenancy\TenantMoved::class)
        ->and($hosts('evil.org')->tenantForHost('acme.example.test'))->toBeNull()
        ->and($hosts('acme')->tenantForHost('acme.example.test'))->toBeNull()
        ->and($hosts('acme-co')->tenantForHost('other.example.test'))->toBeNull();

    try {
        $hosts('acme-co')->tenantForHost('acme.example.test');
    } catch (\Tinvar\Tenancy\TenantMoved $moved) {
        // Escaped past Host, it is the 404 of a host that is no tenant's.
        expect($moved->host)->toBe('acme-co.example.test')->and($moved->status)->toBe(404);
    }
});
