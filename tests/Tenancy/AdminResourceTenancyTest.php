<?php

declare(strict_types=1);

/**
 * An admin resource of a tenant's rows (ADR 0150), as acme on acme's host,
 * reaching for globex's every way its screens and actions can: the list,
 * its search, a record's page and its edit form, an update, a delete, a
 * bulk action naming one of globex's rows among acme's, and a form naming
 * one of globex's records as the one a row belongs to. Every attempt is a
 * 404, a refusal or nothing -- on SQLite, held by the ORM's predicate, and
 * on Postgres, by row-level security too.
 */

namespace Tinvar\Tests\Tenancy\AdminResourceTenancy;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\AdminResource;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column as OrmColumn;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Resources\BulkAction;
use Tinvar\Resources\Column;
use Tinvar\Resources\Field;
use Tinvar\Resources\Resource;
use Tinvar\Resources\Sort;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tenancy\Host;
use Tinvar\Tenancy\TenantHosts;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;
use Tinvar\Tests\Tenancy\Fixtures\Hosts;
use Tinvar\Tests\Tenancy\Fixtures\SignIn;

use function Tinvar\Tests\Orm\Fixtures\withPolicies;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/policies.php';
require_once __DIR__ . '/Fixtures/app.php';

/** A run of a script, with the script it belongs to mapped: what a form may name. */
#[Table('script_runs'), TenantScoped]
final class Run extends Model
{
    #[Id] public private(set) string $id;
    #[OrmColumn] public string $status;
    #[BelongsTo] public Script $script;
}

#[AdminResource(model: Script::class, panel: 'ops'), TenantFrom(Host::class)]
final class ScriptResource extends Resource
{
    public static function columns(): array
    {
        return [Column::make('name')->sortable()->searchable()];
    }

    public static function fields(): array
    {
        return [Field::make('name')];
    }

    public static function bulkActions(): array
    {
        return [BulkAction::delete()];
    }

    public static function sort(): Sort
    {
        return Sort::asc('name');
    }
}

#[AdminResource(model: Run::class, panel: 'ops'), TenantFrom(Host::class)]
final class RunResource extends Resource
{
    public static function columns(): array
    {
        return [Column::make('status'), Column::make('script')];
    }

    public static function fields(): array
    {
        return [Field::make('status'), Field::make('script')];
    }
}

final class App
{
    public readonly Kernel $kernel;
    public readonly Orm $orm;

    public function __construct(PdoConnection $connection)
    {
        $this->orm = new Orm($connection, new IdentityMap('resource-tenancy-test'), Tenancy::flat());
        $container = new Container()->instance(TenantHosts::class, new Hosts())->instance(Orm::class, $this->orm);
        $routes = new RouteRegistry([Script::class, Run::class]);
        $routes->registerAll([ScriptResource::class, RunResource::class]);
        $actions = new ActionRegistry($container->resolver(), [Script::class, Run::class]);
        $actions->registerAll([ScriptResource::class, RunResource::class]);

        $this->kernel = new Kernel(
            orm: $this->orm,
            routes: $routes,
            queries: new QueryRegistry($container->resolver()),
            actions: $actions,
            auth: new AuthContext(),
            policies: new PolicyRegistry(),
            resolver: $container->resolver(),
            tenants: new \Tinvar\Tenancy\Tenancy($container->resolver()),
            allowedQueries: static fn (Request $r): array => [],
            globalMiddleware: [SignIn::class],
            gate: new AllowAllGate(),
            panels: new PanelContext(),
            panelRegistry: new PanelRegistry(new PanelDefinition('ops')),
            container: $container,
        );
    }

    /** @return array<string, mixed>|int the screen's data, or the status it was refused with */
    public function get(string $path, string $host = 'acme.test'): array|int
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $parsed);
        $query = array_filter($parsed, static fn (mixed $value, int|string $key): bool => \is_string($key) && \is_string($value), ARRAY_FILTER_USE_BOTH);
        $response = $this->kernel->handle(
            new Request('GET', (string) parse_url($path, PHP_URL_PATH), ['host' => $host], $query),
            static fn (LoadedRoute $loaded): Response => Response::make(200, json_encode(self::plain($loaded->page), JSON_THROW_ON_ERROR)),
        );

        return $response->status === 200 ? json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR) : $response->status;
    }

    /** @param array<string, mixed> $body */
    public function post(string $action, array $body, string $host = 'acme.test'): Response
    {
        return $this->kernel->handle(
            new Request('POST', "/_action/{$action}", ['host' => $host, 'content-type' => 'application/json', 'accept' => 'application/json', 'rsc' => '1', 'x-xsrf-token' => 'csrf'], cookies: ['__Host-XSRF-TOKEN' => 'csrf']),
            static fn (): Response => Response::make(500, 'not a page'),
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<string> what the table holds for $tenant, read as that tenant afresh */
    public function namesOf(string $tenant): array
    {
        $this->orm->flush();

        return $this->orm->actAs($tenant, fn (): array => array_map(
            static fn (Script $script): string => $script->name,
            $this->orm->query(Script::class)->orderBy('name')->fetch(),
        ));
    }

    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Data => array_map(self::plain(...), get_object_vars($value)),
            \is_array($value) => array_map(self::plain(...), $value),
            $value instanceof \BackedEnum => $value->value,
            default => $value,
        };
    }
}

/**
 * @param \Closure(App, array<string, list<string>>): void $attack given the app and each
 *        tenant's script ids
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

    if ($driver === 'pgsql') {
        $missing = Databases::postgresMissing();
        if ($missing !== null) {
            throw new \PHPUnit\Framework\SkippedWithMessageException($missing);
        }
        withPolicies(static function (PdoConnection $connection, \PDO $pdo) use ($read, $attack): void {
            $ids = [];
            foreach (['acme', 'globex'] as $tenant) {
                $pdo->exec("SELECT set_config('app.tenant', '{$tenant}', false)");
                $ids = [...$ids, ...$read($pdo)];
            }
            $pdo->exec("SELECT set_config('app.tenant', '', false)");
            $attack(new App($connection), $ids);
        });

        return;
    }

    $connection = new TenantFixture()->connection;
    $attack(new App($connection), $read($connection->pdo()));
}

$drivers = ['sqlite' => ['sqlite'], 'pgsql' => ['pgsql']];

it('lists and searches acme\'s rows alone', function (string $driver) {
    onDriver($driver, function (App $app): void {
        $list = $app->get('/scripts');
        $searched = $app->get('/scripts?search=globex');

        expect(\is_array($list) ? array_column($list['rows'], 'title') : $list)->toBe(['acme-script-1', 'acme-script-2'])
            ->and(\is_array($list) ? $list['total'] : null)->toBe(2)
            ->and(\is_array($searched) ? $searched['rows'] : $searched)->toBe([]);
    });
})->with($drivers);

it('answers globex\'s record with a 404, on its page and its edit form', function (string $driver) {
    onDriver($driver, function (App $app, array $ids): void {
        expect($app->get("/scripts/{$ids['globex'][0]}"))->toBe(404)
            ->and($app->get("/scripts/{$ids['globex'][0]}/edit"))->toBe(404)
            ->and(\is_array($app->get("/scripts/{$ids['acme'][0]}")))->toBeTrue();
    });
})->with($drivers);

it('updates and deletes none of globex\'s rows', function (string $driver) {
    onDriver($driver, function (App $app, array $ids): void {
        $update = $app->post('ScriptResource.update', ['record' => $ids['globex'][0], 'values' => ['name' => 'taken']]);
        $destroy = $app->post('ScriptResource.destroy', ['record' => $ids['globex'][0]]);

        expect($update->status)->toBe(404)
            ->and($destroy->status)->toBe(404)
            ->and($app->namesOf('globex'))->toBe(['globex-script-1', 'globex-script-2']);
    });
})->with($drivers);

it('runs a bulk action over none when one of the rows ticked is globex\'s', function (string $driver) {
    onDriver($driver, function (App $app, array $ids): void {
        $response = $app->post('ScriptResource.bulk', ['action' => 'delete', 'ids' => [$ids['acme'][0], $ids['globex'][0]]]);

        expect($response->status)->toBe(422)
            ->and($app->namesOf('acme'))->toBe(['acme-script-1', 'acme-script-2'])
            ->and($app->namesOf('globex'))->toBe(['globex-script-1', 'globex-script-2']);
    });
})->with($drivers);

it('creates a row in acme, and offers and takes acme\'s records alone for one it belongs to', function (string $driver) {
    onDriver($driver, function (App $app, array $ids): void {
        $created = $app->post('ScriptResource.store', ['values' => ['name' => 'acme-script-3']]);
        $form = $app->get('/runs/new');
        $foreign = $app->post('RunResource.store', ['values' => ['status' => 'queued', 'script' => $ids['globex'][0]]]);
        $own = $app->post('RunResource.store', ['values' => ['status' => 'queued', 'script' => $ids['acme'][0]]]);

        expect($created->status)->toBe(409, $created->body())
            ->and($app->namesOf('acme'))->toBe(['acme-script-1', 'acme-script-2', 'acme-script-3'])
            ->and(\is_array($form) ? array_column($form['fields'][1]['options'], 'label') : $form)->toBe(['acme-script-1', 'acme-script-2', 'acme-script-3'])
            ->and($foreign->status)->toBe(422)
            ->and(json_decode($foreign->body(), true)['errors'] ?? null)->toBe(['values.script' => ['Script does not match a record.']])
            ->and($own->status)->toBe(409, $own->body());
    });
})->with($drivers);
