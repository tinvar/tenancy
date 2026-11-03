<?php

declare(strict_types=1);

/**
 * What every tenant store the settings page writes through must hold (ADR
 * 0161): a second tenant never takes a slug in use, on each database, and
 * says so with SlugTaken. And the settings refuse one even through a store
 * of an application's own that does not -- the read before the write is
 * kept as defence in depth, which can only refuse too much.
 */

namespace Tinvar\Tests\Tenancy\SelfService\ProfilesContract;

use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Tenancy\SelfService\DatabaseTenants;
use Tinvar\Tenancy\SelfService\SlugTaken;
use Tinvar\Tenancy\SelfService\TenantProfile;
use Tinvar\Tenancy\SelfService\TenantProfiles;
use Tinvar\Tenancy\SelfService\TenantSettings;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdminWorld;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\Person;
use Tinvar\Validation\ValidationException;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/databases.php';
require_once __DIR__ . '/Fixtures/self_service.php';

/** @param \Closure(DatabaseTenants): void $test */
function onDriver(string $driver, \Closure $test): void
{
    $run = static function (\PDO $pdo) use ($test): void {
        $connection = new PdoConnection($pdo, 'profiles-contract');
        DatabaseTenants::define(new Schema($connection, Dialect::for($connection)));
        $test(new DatabaseTenants($connection));
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

it('never gives a second tenant a slug in use', function (string $driver) {
    if (($missing = missing($driver)) !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, static function (DatabaseTenants $tenants): void {
        $tenants->create('Acme', 'acme', id: 'acme');
        $tenants->create('Globex', 'globex', id: 'globex');

        expect(static fn () => $tenants->create('Acme again', 'acme'))->toThrow(SlugTaken::class)
            ->and(static fn () => $tenants->changeSlug('globex', 'acme'))->toThrow(SlugTaken::class)
            ->and($tenants->tenantForSlug('acme'))->toBe('acme')
            ->and($tenants->slugOf('globex'))->toBe('globex');

        // Its own slug again is no change, and taken by nobody else.
        $tenants->changeSlug('acme', 'acme');
        expect($tenants->tenantForSlug('acme'))->toBe('acme');
    });
})->with(['sqlite', 'pgsql', 'mysql']);

it('refuses a slug in use through the settings, even over a store that would let it through', function () {
    $world = new AdminWorld();
    $careless = new class implements TenantProfiles {
        /** @var array<string, string> tenant => slug */
        public array $slugs = ['acme' => 'acme', 'globex' => 'globex'];

        public function profile(string $tenant): ?TenantProfile
        {
            return isset($this->slugs[$tenant]) ? new TenantProfile($tenant, ucfirst($tenant), $this->slugs[$tenant]) : null;
        }

        public function rename(string $tenant, string $name): void {}

        public function changeSlug(string $tenant, string $slug): void
        {
            // No index, no check: whatever it is asked.
            $this->slugs[$tenant] = $slug;
        }

        public function tenantForSlug(string $slug): ?string
        {
            $tenant = array_search($slug, $this->slugs, true);

            return \is_string($tenant) ? $tenant : null;
        }
    };
    $settings = new TenantSettings($careless, $world->members, $world->kit->invitations, $world->kit->invitePolicy, $world->invites);

    try {
        $settings->changeSlug('globex', 'acme', new Person('m-9', 'gil@example.test', 'Gil'));
        $refused = null;
    } catch (ValidationException $e) {
        $refused = $e->errors['slug'][0] ?? null;
    }

    expect($refused)->toBe("Another organization uses 'acme'. Choose another.")
        ->and($careless->slugs['globex'])->toBe('globex');
});
