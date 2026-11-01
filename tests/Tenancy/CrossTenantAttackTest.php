<?php

declare(strict_types=1);

/**
 * Signed in as acme's member, reaching for globex's rows every way a
 * request can: a route's model, an action's argument, a query, the
 * builder's where and whereIn, a relation eager-loaded and lazily read, a
 * raw condition, the Exists and Unique rules, the builder's writes, and a
 * save that moves a row. Every attempt is a 404, a refusal or nothing --
 * never one of globex's rows -- on SQLite, held by the ORM's predicate,
 * and on Postgres, held by the predicate and by row-level security, which
 * also holds a read that goes round the ORM altogether.
 */

namespace Tinvar\Tests\Tenancy\CrossTenantAttack;

use Tinvar\Orm\ForeignTenantRow;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;
use Tinvar\Tests\Tenancy\Fixtures\App;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Exists;
use Tinvar\Validation\Rules\Unique;
use Tinvar\Validation\Validator;

use function Tinvar\Tests\Orm\Fixtures\withPolicies;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/policies.php';
require_once __DIR__ . '/Fixtures/app.php';

final readonly class NamesAScript extends FormRequest
{
    public function __construct(#[Exists(Script::class, 'name')] public string $name) {}
}

final readonly class NamesANewScript extends FormRequest
{
    public function __construct(#[Unique(Script::class, 'name')] public string $name) {}
}

/** A script that maps its tenant column, as an application may to show it. */
#[\Tinvar\Orm\Attributes\Table('scripts'), \Tinvar\Orm\Attributes\TenantScoped]
final class ScriptShowingTenant extends \Tinvar\Orm\Model
{
    #[\Tinvar\Orm\Attributes\Id] public private(set) string $id;
    #[\Tinvar\Orm\Attributes\Column] public string $name;
    #[\Tinvar\Orm\Attributes\Column('tenant_id')] public string $tenantId;
}

/**
 * Runs $attack against two tenants' scripts and runs, on the driver named:
 * SQLite, or Postgres with the policies on, as a role they hold to. Each
 * of acme's scripts also has a run globex wrote pointing at it -- a row a
 * join or an eager load that forgot the tenant would bring back.
 *
 * @param \Closure(PdoConnection, array<string, list<string>>): void $attack given the
 *        connection and each tenant's script ids
 */
function onDriver(string $driver, \Closure $attack): void
{
    $read = static function (\PDO $pdo): array {
        $ids = [];
        foreach ($pdo->query('SELECT id, tenant_id FROM scripts ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $ids[(string) $row['tenant_id']][] = (string) $row['id'];
        }

        return $ids;
    };
    $plant = static function (\PDO $pdo, array $acme): void {
        foreach ($acme as $script) {
            $pdo->prepare('INSERT INTO script_runs (id, status, script_id, tenant_id) VALUES (?, ?, ?, ?)')->execute([Uuid::v7(), 'planted', $script, 'globex']);
        }
    };

    if ($driver === 'pgsql') {
        $missing = Databases::postgresMissing();
        if ($missing !== null) {
            throw new \PHPUnit\Framework\SkippedWithMessageException($missing);
        }
        // The policy lets a session read and plant globex's rows only as globex.
        withPolicies(static function (PdoConnection $connection, \PDO $pdo) use ($read, $plant, $attack): void {
            $pdo->exec("SELECT set_config('app.tenant', 'acme', false)");
            $acme = $read($pdo);
            $pdo->exec("SELECT set_config('app.tenant', 'globex', false)");
            $globex = $read($pdo);
            $plant($pdo, $acme['acme']);
            $pdo->exec("SELECT set_config('app.tenant', '', false)");
            $attack($connection, [...$acme, ...$globex]);
        });

        return;
    }

    $connection = new TenantFixture()->connection;
    $ids = $read($connection->pdo());
    $plant($connection->pdo(), $ids['acme']);
    $attack($connection, $ids);
}

function orm(PdoConnection $connection): Orm
{
    return new Orm($connection, new IdentityMap('attack-test'), Tenancy::flat());
}

$drivers = [
    'sqlite' => ['sqlite'],
    'pgsql' => ['pgsql'],
];

/** @return list<string> */
function names(array $models): array
{
    return array_map(static fn (object $model): string => $model->name ?? $model->status, $models);
}

describe('through the kernel, as acme on acme\'s host', function () use ($drivers) {
    it('finds globex\'s script by no route, action or query', function (string $driver) {
        onDriver($driver, function (PdoConnection $connection, array $ids): void {
            $app = new App($connection);

            $route = $app->get('/t/scripts/' . $ids['globex'][0], 'acme.test');
            $action = $app->post('/_action/ScriptActions.touch', 'acme.test', ['script' => $ids['globex'][0]]);
            $query = App::json($app->post('/_query/scripts.names', 'acme.test', ['args' => new \stdClass()]))['value'] ?? null;

            expect($route->status)->toBe(404)
                ->and($action->status)->toBe(404)
                ->and($query)->toBe(['acme-script-1', 'acme-script-2']);
        });
    })->with($drivers);
});

describe('through the ORM, as acme', function () use ($drivers) {
    it('reads none of globex\'s rows by where, whereIn or find', function (string $driver) {
        onDriver($driver, function (PdoConnection $connection, array $ids): void {
            $orm = orm($connection);

            $read = $orm->actAs('acme', static fn (): array => [
                names($orm->query(Script::class)->where('name', 'globex-script-1')->fetch()),
                names($orm->query(Script::class)->whereIn('id', [...$ids['globex'], $ids['acme'][0]])->fetch()),
                $orm->find(Script::class, $ids['globex'][0]),
                names($orm->whereIn(Script::class, 'id', $ids['globex'])),
            ]);

            expect($read)->toBe([[], ['acme-script-1'], null, []]);
        });
    })->with($drivers);

    it('loads none of globex\'s children through a relation, eagerly or lazily', function (string $driver) {
        onDriver($driver, function (PdoConnection $connection, array $ids): void {
            $orm = orm($connection);

            [$eager, $lazy] = $orm->actAs('acme', static function () use ($orm, $ids): array {
                $eager = $orm->query(Script::class)->with('runs')->where('id', $ids['acme'][0])->first();
                $orm->identityMap()->flush();
                $lazy = $orm->find(Script::class, $ids['acme'][1]);

                return [names($eager?->runs->all() ?? []), names($lazy?->runs->all() ?? [])];
            });

            expect($eager)->toBe(['ok'])->and($lazy)->toBe(['ok']);
        });
    })->with($drivers);

    it('reads none of globex\'s rows through a raw condition that ORs past the tenant', function (string $driver) {
        onDriver($driver, function (PdoConnection $connection, array $ids): void {
            $orm = orm($connection);

            $read = $orm->actAs('acme', static fn (): array => names(
                $orm->query(Script::class)->whereRaw('name = ? OR 1 = 1', ['globex-script-1'])->orderBy('name')->fetch(),
            ));

            expect($read)->toBe(['acme-script-1', 'acme-script-2']);
        });
    })->with($drivers);

    it('takes globex\'s names as no record for Exists, and as free for Unique', function (string $driver) {
        onDriver($driver, function (PdoConnection $connection, array $ids): void {
            $orm = orm($connection);
            $validator = new Validator($orm);

            $judged = $orm->actAs('acme', static fn (): array => [
                $validator->passes(NamesAScript::class, ['name' => 'acme-script-1']),
                $validator->passes(NamesAScript::class, ['name' => 'globex-script-1']),
                $validator->passes(NamesANewScript::class, ['name' => 'acme-script-1']),
                $validator->passes(NamesANewScript::class, ['name' => 'globex-script-1']),
            ]);

            // Exists finds acme's own and not globex's; Unique is taken by
            // acme's own and says nothing of whether globex holds one.
            expect($judged)->toBe([true, false, false, true]);
        });
    })->with($drivers);

    it('updates and deletes none of globex\'s rows, and inserts none into globex', function (string $driver) {
        onDriver($driver, function (PdoConnection $connection, array $ids): void {
            $orm = orm($connection);

            $written = $orm->actAs('acme', static fn (): array => [
                $orm->query(Script::class)->whereIn('id', $ids['globex'])->update(['name' => 'taken']),
                $orm->query(Script::class)->whereIn('id', $ids['globex'])->delete(),
                $orm->query(Script::class)->whereRaw('1 = 1')->where('name', 'globex-script-2')->update(['name' => 'taken']),
            ]);
            $insert = static fn () => $orm->actAs('acme', static fn (): int => $orm->query(Script::class)->insert([['name' => 'planted', 'tenant_id' => 'globex']]));
            $upsert = static fn () => $orm->actAs('acme', static fn (): int => $orm->query(Script::class)->upsert([['id' => $ids['globex'][0], 'name' => 'taken']], uniqueBy: 'id'));
            $move = static fn () => $orm->actAs('acme', static fn (): int => $orm->query(Script::class)->where('id', $ids['acme'][0])->update(['tenant_id' => 'globex']));

            expect($written)->toBe([0, 0, 0])
                ->and($insert)->toThrow(ForeignTenantRow::class)
                ->and($move)->toThrow(ForeignTenantRow::class);

            try {
                $upsert();
            } catch (\Throwable) {
                // Refused is as good as left alone; which, is the dialect's.
            }

            expect($orm->actAs('globex', static fn (): array => names($orm->query(Script::class)->orderBy('name')->fetch())))
                ->toBe(['globex-script-1', 'globex-script-2']);
        });
    })->with($drivers);

    it('refuses a save that moves acme\'s row into globex', function (string $driver) {
        onDriver($driver, function (PdoConnection $connection, array $ids): void {
            $orm = orm($connection);

            $save = static fn () => $orm->actAs('acme', static function () use ($orm, $ids): void {
                $script = $orm->find(ScriptShowingTenant::class, $ids['acme'][0]);
                \assert($script !== null);
                $script->tenantId = 'globex';
                $orm->save($script);
            });

            expect($save)->toThrow(ForeignTenantRow::class)
                ->and($orm->actAs('globex', static fn (): ?ScriptShowingTenant => $orm->find(ScriptShowingTenant::class, $ids['acme'][0])))->toBeNull();
        });
    })->with($drivers);
});

describe('around the ORM, on Postgres', function () {
    it('holds a raw read and a raw write to the tenant the request runs as', function () {
        onDriver('pgsql', function (PdoConnection $connection, array $ids): void {
            $orm = orm($connection);

            [$read, $written] = $orm->actAs('acme', static fn (): array => [
                array_map(static fn (array $row): string => (string) $row['name'], $connection->select('SELECT name FROM scripts ORDER BY name')),
                $connection->execute("UPDATE scripts SET name = 'taken' WHERE tenant_id = 'globex'"),
            ]);

            expect($read)->toBe(['acme-script-1', 'acme-script-2'])
                ->and($written)->toBe(0)
                ->and($orm->actAs('globex', static fn (): array => names($orm->query(Script::class)->orderBy('name')->fetch())))
                ->toBe(['globex-script-1', 'globex-script-2']);
        });
    })->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');
});
