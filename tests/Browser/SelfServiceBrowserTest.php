<?php

declare(strict_types=1);

/**
 * The tenant self-service pages' shipped views (ADR 0161) in a real
 * browser: the fixture application (app/Fixture.php) behind php -S, the
 * Node renderer over its socket with the fixture's build of the package's
 * own views, Chromium driving them (packages/tenancy/e2e/self-service.mjs).
 *
 * Every other test of the pages reads their data; this one is where the
 * views draw it, their forms post it with the session's CSRF token once
 * hydrated, and the page an action revalidates is the one on screen.
 *
 * Skipped when its prerequisites are missing: Node, the JavaScript
 * dependencies the build needs, or Playwright's Chromium.
 */

namespace Tinvar\Tests\Tenancy\Browser;

use Tinvar\Bench\Renderer;

require_once __DIR__ . '/app/Fixture.php';

/** The repository's root, whatever directory the suite runs from. */
function repositoryRoot(): string
{
    return \dirname(__DIR__, 4);
}

/**
 * Runs a command and returns its combined output, killing it past the
 * deadline: a browser that hangs must fail the test, not hang it.
 *
 * @param list<string> $command
 * @param array<string, string> $env
 */
function runFor(array $command, string $cwd, int $seconds, array $env = []): string
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp', ...$env]);
    if (!\is_resource($process)) {
        return '[could not start ' . implode(' ', $command) . ']';
    }
    stream_set_blocking($pipes[1], false);

    $output = '';
    $deadline = microtime(true) + $seconds;
    while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
        $output .= (string) stream_get_contents($pipes[1]);
        usleep(50_000);
    }
    $output .= (string) stream_get_contents($pipes[1]);
    if (proc_get_status($process)['running']) {
        proc_terminate($process, 9);
        $output .= "\n[timed out after {$seconds}s]";
    }
    proc_close($process);

    return $output;
}

/** The newest modification time of any file under $paths. */
function newestSource(array $paths): int
{
    $newest = 0;
    foreach ($paths as $path) {
        if (is_file($path)) {
            $newest = max($newest, (int) filemtime($path));

            continue;
        }
        if (!is_dir($path)) {
            continue;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $newest = max($newest, (int) $file->getMTime());
        }
    }

    return $newest;
}

/**
 * Builds the fixture with Vite when its build is missing or older than its
 * sources -- its entries and config, and the package's views. Null once
 * built; why not, otherwise.
 */
function ensureFixtureBuilt(): ?string
{
    $root = repositoryRoot();
    $app = __DIR__ . '/app';
    $entry = "{$app}/dist/rsc/index.js";
    if (!is_file("{$root}/node_modules/vite/package.json") || !is_file("{$root}/node_modules/@vitejs/plugin-rsc/package.json")) {
        return 'The JavaScript dependencies are not installed (npm ci at the repository root).';
    }
    $sources = newestSource(["{$app}/resources", "{$app}/vite.config.ts", \dirname(__DIR__, 2) . '/resources']);
    if (is_file($entry) && filemtime($entry) >= $sources) {
        return null;
    }

    $output = runFor(['node', "{$root}/node_modules/vite/bin/vite.js", 'build'], $app, 300);
    if (!is_file($entry) || filemtime($entry) < $sources) {
        return "The tenancy browser fixture did not build:\n" . implode("\n", \array_slice(explode("\n", $output), -30));
    }
    // Every built file newer than the sources, so the next run builds nothing.
    touch($entry);

    return null;
}

beforeAll(function (): void {
    $root = repositoryRoot();
    // The renderer is started by the repository's bench helpers, which a
    // package run alone (bin/package-alone) does not install.
    if (!class_exists(Renderer::class)) {
        test()->markTestSkipped('The repository\'s bench helpers are not installed: run this from the Tinvar repository.');
    }
    if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
        test()->markTestSkipped('Node is not installed.');
    }
    if (!is_file("{$root}/node_modules/playwright/package.json")) {
        test()->markTestSkipped('Playwright is not installed (npm ci at the repository root).');
    }
    $chromium = trim(runFor(['node', '-e', "const { chromium } = require('playwright'); process.stdout.write(require('fs').existsSync(chromium.executablePath()) ? 'yes' : 'no')"], $root, 30));
    if ($chromium !== 'yes') {
        test()->markTestSkipped("Playwright's Chromium is not installed (npx playwright install chromium).");
    }
    $unbuilt = ensureFixtureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

test('the shipped self-service views add, check and remove a domain, rename, invite and switch, in a browser', function (): void {
    $root = repositoryRoot();
    $directory = sys_get_temp_dir() . '/tinvar-tenancy-e2e-' . bin2hex(random_bytes(4));
    mkdir($directory, 0o700);
    $database = "{$directory}/app.sqlite";
    $dns = "{$directory}/dns.json";
    $invites = "{$directory}/invites.jsonl";
    $log = "{$directory}/php.log";
    file_put_contents($dns, '{}');
    file_put_contents($invites, '');
    SelfServiceApp::seed($database);

    // A port known before php -S starts, for the realms and Domains the
    // fixture builds from it: asked of the system, let go, bound again by
    // php -S, which says so once it has.
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    expect($probe)->not->toBeFalse();
    $listen = (string) stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr($listen, strrpos($listen, ':') + 1);

    $renderer = null;
    $server = null;
    try {
        $renderer = Renderer::start('msgpack', 'tenancy-e2e', __DIR__ . '/app/dist/rsc/index.js');
        $server = proc_open(
            [PHP_BINARY, '-S', $listen, __DIR__ . '/app/public/index.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'w']],
            $pipes,
            __DIR__ . '/app/public',
            [
                ...array_filter(getenv(), \is_string(...)),
                'TINVAR_SOCKET' => $renderer->socketPath,
                'TENANCY_E2E_DATABASE' => $database,
                'TENANCY_E2E_PORT' => (string) $port,
                'TENANCY_E2E_DNS' => $dns,
                'TENANCY_E2E_INVITES' => $invites,
            ],
        );
        expect($server)->toBeResource();

        $started = false;
        $deadline = microtime(true) + 10;
        while (!$started && proc_get_status($server)['running'] && microtime(true) < $deadline) {
            $started = str_contains((string) file_get_contents($log), "Development Server (http://{$listen}) started");
            if (!$started) {
                usleep(50_000);
            }
        }
        expect($started)->toBeTrue('php -S did not start: ' . file_get_contents($log));

        $output = runFor(['node', \dirname(__DIR__, 2) . '/e2e/self-service.mjs', (string) $port, $dns, $invites], $root, 180);
        $lines = array_values(array_filter(explode("\n", trim($output)), static fn (string $line): bool => str_starts_with($line, '{')));
        $report = json_decode((string) end($lines), true);
        $context = "{$output}\n--- php -S:\n" . file_get_contents($log) . "\n--- renderer:\n" . $renderer->log();
        expect($report)->toBeArray($context);
        expect($report['failure'] ?? null)->toBeNull($context);

        // 1. Custom domains.
        $domains = $report['domains'];
        expect($domains['emptyBanner'])->toBeTrue()
            ->and($domains['added']['status'])->toBe('pending')
            ->and($domains['added']['badge'])->toBeTrue()
            ->and($domains['added']['emptyBanner'])->toBeFalse();
        $records = [];
        foreach ($domains['added']['records'] as [$type, $name, $value]) {
            $records[$type] = [$name, $value];
        }
        expect($records['TXT'][0])->toBe('_tinvar-challenge.shop.acme.test')
            ->and($records['TXT'][1])->toMatch('/^tinvar-domain=[A-Za-z0-9_-]{16,}$/')
            ->and($records['CNAME'][1])->toBe('domains.example.test');
        expect($domains['checked'])->toBe(['status' => 'verified', 'badge' => true, 'records' => 0])
            ->and($domains['removed'])->toBe(['gone' => true, 'emptyBanner' => true])
            ->and($domains['sameDocument'])->toBeTrue('the forms submitted in place, hydrated');

        // 2. Settings, as the owner.
        $settings = $report['settings'];
        expect($settings['heading'])->toBe('Acme')
            ->and($settings['renamed'])->toBe('Acme Rockets')
            ->and($settings['invited'])->toBe([['dee@example.net', 'Admin']])
            ->and($settings['revoked'])->toBeTrue()
            ->and($settings['sameDocument'])->toBeTrue()
            ->and($settings['inviteLink'])->toBe(['host' => "acme.localhost:{$port}", 'path' => '/invite', 'count' => 2])
            ->and($settings['invitation']['heading'])->toBe('Join Acme Rockets')
            ->and($settings['accepted']['path'])->toBe('/settings')
            ->and($settings['accepted']['members'])->toContain('Dee', 'Ann', 'Bob', 'Cy')
            ->and($settings['accepted']['sameDocument'])->toBeTrue('the accepted invite went on by a client visit');
        expect($report['clientVisit'])->toBe(['path' => '/invite', 'sameDocument' => true]);

        // 3. A member draws no forms.
        expect($report['member'])->toBe([
            'heading' => 'Acme Rockets',
            'members' => $report['member']['members'],
            'renameForms' => 0,
            'inviteForms' => 0,
            'forms' => 0,
        ])->and($report['member']['members'])->toContain('Ann', 'Bob', 'Cy');

        // 4. The switcher.
        expect($report['switcher'])->toBe([
            'tenants' => ['acme', 'globex'],
            'acmeCurrent' => true,
            'globexHost' => true,
            'navigatedTo' => "http://globex.localhost:{$port}/_handoff/start?return=%2F",
            'refused' => null,
        ]);

        // 5. Another tenant, where Ann is a member.
        expect($report['crossTenant']['heading'])->toBe('Globex')
            ->and($report['crossTenant']['members'])->toEqualCanonicalizing(['Eve', 'Ann'])
            ->and($report['crossTenant']['forms'])->toBe(0);

        // 6. Nothing went wrong on any page.
        expect($report['errors'])->toBe([], $context);
    } finally {
        if (\is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
        $renderer?->stop();
        foreach (glob("{$directory}/{,.}*", GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($directory);
    }
});
