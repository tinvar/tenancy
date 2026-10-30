<?php

declare(strict_types=1);

/**
 * Tenants for work with no request behind it: a command placed by its
 * `--tenant=`, and a scheduled task visiting every tenant in turn.
 *
 * "The route, webhook, command or job declares where its tenant comes
 * from: ... or a command argument." "Scheduled tasks and migrations run
 * with no tenant, so tenant-scoped work in them must use forEachTenant()
 * or acrossTenants()" (tinvar-spec.md). The console runs a command's
 * declared tenant through the Tenancy registered with it (CommandTenants,
 * ADR 0056); the framework's own console tests run with none.
 */

namespace Tinvar\Tests\Tenancy\TenantCommands;

use Tinvar\Attributes\Command as CommandName;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Schedule;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Console\ArrayScheduleStore;
use Tinvar\Console\Command;
use Tinvar\Console\Console;
use Tinvar\Console\Input;
use Tinvar\Console\Output;
use Tinvar\Console\Scheduler;
use Tinvar\Http\Request;
use Tinvar\Orm\Orm;
use Tinvar\Tenancy\Host;
use Tinvar\Tenancy\Tenancy;
use Tinvar\Tenancy\TenantArgument;
use Tinvar\Tenancy\TenantDirectory;
use Tinvar\Tenancy\Tenants;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Journal;
use Tinvar\Tests\Orm\Fixtures\Script;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/framework') . '/tests/Jobs/Fixtures/jobs.php';

final class TwoTenants implements TenantDirectory
{
    public function has(string $tenant): bool
    {
        return \in_array($tenant, ['acme', 'globex'], true);
    }

    public function all(): iterable
    {
        return ['acme', 'globex'];
    }
}

/** Lists the tenant's scripts: tenant-scoped work, so it needs a tenant. */
#[CommandName('scripts:list', description: 'The tenant\'s scripts'), TenantFrom(TenantArgument::class)]
final class ListScripts extends Command
{
    public function __construct(private readonly Orm $orm, private readonly AuthContext $auth) {}

    public function handle(Input $input, Output $output): int
    {
        foreach ($this->orm->all(Script::class, 'name') as $script) {
            $output->line($script->name);
        }
        $output->line('user: ' . ($this->auth->id() ?? 'nobody'));
        $output->line('extra: ' . ($input->argument(0) ?? '-'));

        return 0;
    }
}

/** Written with the name TenantArgument had in the framework's Console. */
#[CommandName('scripts:old'), TenantFrom(\Tinvar\Console\TenantArgument::class)]
final class OldNameScripts extends Command
{
    public function __construct(private readonly Orm $orm) {}

    public function handle(Input $input, Output $output): int
    {
        $output->line($this->orm->tenant());

        return 0;
    }
}

#[CommandName('scripts:count')]
final class CountScripts extends Command
{
    public function __construct(private readonly Orm $orm) {}

    public function handle(Input $input, Output $output): int
    {
        $output->line((string) \count($this->orm->all(Script::class)));

        return 0;
    }
}

#[CommandName('wrong:source'), TenantFrom(Host::class)]
final class HostCommand extends Command
{
    public function handle(Input $input, Output $output): int
    {
        return 0;
    }
}

/** Every ten minutes: counts each tenant's scripts, one tenant at a time. */
#[Schedule('*/10 * * * *')]
final class CountEachTenant
{
    public function __invoke(Tenants $tenants, Orm $orm, Journal $journal): void
    {
        $journal->entries[] = ['task tenant' => $orm->tenant()];
        $tenants->forEachTenant(function (string $tenant) use ($orm, $journal): void {
            $journal->entries[] = [$tenant => array_map(static fn (Script $s): string => $s->name, $orm->all(Script::class, 'name'))];
        });
    }
}

/** At 03:05 daily, in the admin panel, and it fails. */
#[Schedule('5 3 * * *'), Panel('admin')]
final class NightlyFails
{
    public function __invoke(PanelContext $panels, Journal $journal): void
    {
        $journal->entries[] = ['nightly panel' => $panels->current()];

        throw new \RuntimeException('nightly failed on purpose');
    }
}

/**
 * @param list<class-string<Command>> $commands
 * @return array{Console, Output, Harness, \Closure(): array{list<string>, list<string>}}
 */
function console(array $commands = [ListScripts::class, CountScripts::class], ?Harness $h = null): array
{
    $h ??= new Harness();
    $out = [];
    $err = [];
    $output = new Output(static function (string $line) use (&$out): void {
        $out[] = $line;
    }, static function (string $line) use (&$err): void {
        $err[] = $line;
    });
    $console = new Console(
        $commands,
        $h->orm,
        $h->container->resolver(),
        $h->scope,
        $h->panels,
        $h->registry,
        tenants: new Tenancy($h->container->resolver(), new TwoTenants()),
    );

    return [$console, $output, $h, function () use (&$out, &$err): array {
        return [$out, $err];
    }];
}

it('runs a command as the tenant its --tenant names, and as nobody', function () {
    [$console, $output, $h, $read] = console();
    $h->auth->login(new \Tinvar\Tests\Jobs\Fixtures\User('left-over'));

    expect($console->run('scripts:list', ['--tenant=acme', 'more'], $output))->toBe(0)
        ->and($read()[0])->toBe(['acme-script-1', 'acme-script-2', 'user: nobody', 'extra: more'])
        // The tenant was the command's for its run alone.
        ->and($h->orm->tenant())->toBe('');
});

it('refuses a command run without its tenant, with an unknown one, or with one it does not use', function () {
    [$console, $output, , $read] = console();

    expect($console->run('scripts:list', [], $output))->toBe(Console::USAGE)
        ->and($console->run('scripts:list', ['--tenant=initech'], $output))->toBe(Console::USAGE)
        ->and($console->run('scripts:list', ['--tenant='], $output))->toBe(Console::USAGE)
        ->and($console->run('scripts:count', ['--tenant=acme'], $output))->toBe(Console::USAGE)
        ->and($read()[1])->toBe([
            'scripts:list runs as a tenant: name it with --tenant=ID.',
            "There is no tenant 'initech'.",
            'scripts:list runs as a tenant: name it with --tenant=ID.',
            'scripts:count does not run as a tenant, so --tenant would scope nothing.',
        ]);
});

// A command still written with the old name is placed exactly as one
// written with the new: the source is compared as a class, never by name.
it('places a command declaring TenantArgument by the name it had in Console', function () {
    set_error_handler(static fn (int $level): bool => $level === \E_USER_DEPRECATED);
    try {
        [$console, $output, , $read] = console([OldNameScripts::class]);
    } finally {
        restore_error_handler();
    }

    expect($console->run('scripts:old', ['--tenant=globex'], $output))->toBe(0)
        ->and($read()[0])->toBe(['globex']);
});

it('refuses a command whose tenant would come from a request', function () {
    expect(fn () => console([HostCommand::class]))->toThrow(\InvalidArgumentException::class, 'reads a request');
});

it('refuses a command declaring its argument with no directory to check it against', function () {
    $h = new Harness();

    expect(fn () => new Console([ListScripts::class], $h->orm, $h->container->resolver(), $h->scope, tenants: new Tenancy($h->container->resolver())))
        ->toThrow(\InvalidArgumentException::class, 'no TenantDirectory');
});

it('places no request with a command argument, so on a page it fails closed', function () {
    expect(new TenantArgument()->tenantFor(Request::get('/')))->toBeNull();
});

it('runs due tasks with no tenant, forEachTenant() visiting each tenant in turn', function () {
    $h = new Harness();
    $h->container->instance(Tenants::class, new Tenants($h->orm, new TwoTenants()));
    $reports = [];
    $scheduler = new Scheduler(
        [CountEachTenant::class, NightlyFails::class],
        $h->orm,
        $h->container->resolver(),
        $h->scope,
        new ArrayScheduleStore(),
        $h->panels,
        $h->registry,
        static function (\Throwable $e) use (&$reports): void {
            $reports[] = $e->getMessage();
        },
    );

    // A worker left running as a tenant: the task still runs as none.
    $h->orm->switchTenant('acme');
    expect($scheduler->due(new \DateTimeImmutable('2026-10-24T10:20:00Z')))->toBe([CountEachTenant::class])
        ->and($scheduler->runDue(new \DateTimeImmutable('2026-10-24T10:20:30Z')))->toBe([CountEachTenant::class => 'ran'])
        ->and($h->journal()->entries)->toBe([
            ['task tenant' => ''],
            ['acme' => ['acme-script-1', 'acme-script-2']],
            ['globex' => ['globex-script-1', 'globex-script-2']],
        ]);

    // Due again the same minute -- a second scheduler, or a cron overlapping
    // schedule:work -- it does not run twice.
    expect($scheduler->runDue(new \DateTimeImmutable('2026-10-24T10:20:50Z')))->toBe([CountEachTenant::class => 'skipped']);

    // A task that throws is reported; it ran in its panel.
    expect($scheduler->runDue(new \DateTimeImmutable('2026-10-24T03:05:00Z')))->toBe([NightlyFails::class => 'failed'])
        ->and($reports)->toBe(['nightly failed on purpose'])
        ->and(end($h->journal()->entries))->toBe(['nightly panel' => 'admin'])
        ->and($h->panels->currentOrNull())->toBeNull();
});

it('refuses forEachTenant() from work already running as a tenant', function () {
    $h = new Harness();
    $tenants = new Tenants($h->orm, new TwoTenants());

    expect(fn () => $h->orm->actAs('acme', fn () => $tenants->forEachTenant(static fn () => null)))
        ->toThrow(\LogicException::class, 'would reach every other tenant');
});
