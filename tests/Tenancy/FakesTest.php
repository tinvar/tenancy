<?php

declare(strict_types=1);

/**
 * Tenancy's fakes, for an application's tests: a source that answers what
 * the test says and nothing else, a switcher that refuses as the real one
 * does, and sessions that hand back a new id on each switch -- none made
 * in a process that says production.
 */

namespace Tinvar\Tests\Tenancy\Fakes;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Routing\HostMatch;
use Tinvar\Tenancy\Fakes\SubtreeSourceFake;
use Tinvar\Tenancy\Fakes\TenantSessionsFake;
use Tinvar\Tenancy\Fakes\TenantSourceFake;
use Tinvar\Tenancy\Fakes\TenantSwitcherFake;
use Tinvar\Tenancy\SubtreeSource;
use Tinvar\Tenancy\TenantSwitcher;
use Tinvar\Tenancy\TenantSwitching;

function at(string $host): Request
{
    return new Request('GET', '/', ['host' => $host]);
}

/** Runs $body with TINVAR_ENV=production, and puts it back. */
function inProduction(\Closure $body): void
{
    $before = getenv('TINVAR_ENV');
    putenv('TINVAR_ENV=production');
    try {
        $body();
    } finally {
        putenv($before === false ? 'TINVAR_ENV' : "TINVAR_ENV={$before}");
    }
}

describe('a fake source', function () {
    it('names a host\'s tenant as Host reads a host, and none for a host it was not told', function () {
        $source = TenantSourceFake::hosts(['acme.shop.test' => 'acme']);

        expect($source->tenantFor(at('ACME.shop.test:8443')))->toBe('acme')
            ->and($source->tenantFor(at('acme.shop.test.')))->toBe('acme')
            ->and($source->tenantFor(at('globex.shop.test')))->toBeNull()
            ->and($source->tenantFor(at('127.0.0.1')))->toBeNull()
            ->and($source->asked())->toHaveCount(4);
    });

    // Where the application routes by host, Host reads the host the Kernel
    // resolved -- a pinned proxy's X-Forwarded-Host among them -- and a
    // fixed host of the platform's is no tenant's. The fake reads the same.
    it('reads the resolved host where the application routes by host, as Host does', function () {
        $source = TenantSourceFake::hosts(['acme.shop.test' => 'acme', 'www.shop.test' => 'www']);
        $resolved = static fn (string $header, HostMatch $match): Request => at($header)->withAttribute(HostMatch::ATTRIBUTE, $match);

        expect($source->tenantFor($resolved('proxy.internal', new HostMatch('{tenant}.shop.test', 'acme.shop.test', ['tenant' => 'acme']))))->toBe('acme')
            ->and($source->tenantFor($resolved('acme.shop.test', new HostMatch(null, ''))))->toBeNull()
            ->and($source->tenantFor($resolved('www.shop.test', new HostMatch('www.shop.test', 'www.shop.test'))))->toBeNull();
    });

    it('answers one tenant for all, or none', function () {
        expect(TenantSourceFake::always('acme')->tenantFor(at('anything.test')))->toBe('acme')
            ->and(TenantSourceFake::never()->tenantFor(at('acme.shop.test')))->toBeNull();
    });

    it('bounds the request where it stands in for a host', function () {
        expect(SubtreeSourceFake::always('acme'))->toBeInstanceOf(SubtreeSource::class)
            ->and(TenantSourceFake::always('acme'))->not->toBeInstanceOf(SubtreeSource::class);
    });

    it('refuses an empty tenant or a host spelled as no request carries it', function (\Closure $make) {
        $make();
    })->with([
        'empty tenant' => [fn () => TenantSourceFake::always('')],
        'empty mapped tenant' => [fn () => TenantSourceFake::hosts(['acme.test' => ''])],
        'uppercase host' => [fn () => TenantSourceFake::hosts(['ACME.test' => 'acme'])],
    ])->throws(\InvalidArgumentException::class);
});

describe('a fake switcher', function () {
    it('stands where the real one does', function () {
        expect(TenantSwitcherFake::offering([]))->toBeInstanceOf(TenantSwitching::class)
            ->and(is_subclass_of(TenantSwitcher::class, TenantSwitching::class))->toBeTrue();
    });

    it('offers what the test says, and records each switch under a new session', function () {
        $switcher = TenantSwitcherFake::offering(['admin' => ['acme', 'globex']]);

        $first = $switcher->switch(at('shop.test'), 'globex', 'admin');
        $second = $switcher->switch(at('shop.test'), 'acme', 'admin');

        expect($switcher->choices(at('shop.test'), 'admin'))->toBe(['acme', 'globex'])
            ->and($switcher->choices(at('shop.test'), 'staff'))->toBe([])
            ->and($first)->toMatch('/^[0-9a-f]{32}$/')
            ->and($second)->not->toBe($first)
            ->and(array_column($switcher->switches(), 'tenant'))->toBe(['globex', 'acme']);
    });

    it('refuses a tenant not offered with the real one\'s one 403, and records nothing', function (string $tenant, string $panel) {
        $switcher = TenantSwitcherFake::offering(['admin' => ['acme']]);
        try {
            $switcher->switch(at('shop.test'), $tenant, $panel);
            $this->fail('The switch went through.');
        } catch (HttpException $e) {
            expect($e->status)->toBe(403)->and($e->code_)->toBe('tenant_switch_refused')
                ->and($switcher->switches())->toBe([]);
        }
    })->with([['globex', 'admin'], ['acme', 'staff'], ['', 'admin']]);

    it('answers 401 with nobody signed in', function () {
        TenantSwitcherFake::signedOut()->choices(at('shop.test'), 'admin');
    })->throws(HttpException::class, 'someone signed in');
});

it('starts a new session on each switch, for the real switcher to hand back', function () {
    $sessions = new TenantSessionsFake();
    $user = new class implements Authenticatable {
        public function authIdentifier(): string
        {
            return 'user-1';
        }
    };

    $one = $sessions->switchTo(at('shop.test'), $user, 'acme');
    $two = $sessions->switchTo(at('shop.test'), $user, 'acme');

    expect($one)->not->toBe($two)
        ->and($sessions->started())->toBe([
            ['user' => 'user-1', 'tenant' => 'acme', 'session' => $one],
            ['user' => 'user-1', 'tenant' => 'acme', 'session' => $two],
        ]);
});

it('makes none of them in a process that says production', function (\Closure $make) {
    inProduction(function () use ($make): void {
        expect($make)->toThrow(\LogicException::class, 'refused: TINVAR_ENV=production');
    });
})->with([
    'source' => [fn () => TenantSourceFake::always('acme')],
    'subtree source' => [fn () => SubtreeSourceFake::hosts([])],
    'switcher' => [fn () => TenantSwitcherFake::offering([])],
    'sessions' => [fn () => new TenantSessionsFake()],
]);

it('makes none of them where nothing says this is a test\'s machine (ADR 0213)', function () {
    $before = getenv('TINVAR_ENV');
    putenv('TINVAR_ENV');
    try {
        expect(fn () => TenantSourceFake::always('acme'))->toThrow(\LogicException::class, 'refused: TINVAR_ENV is not local, development or testing');
    } finally {
        putenv($before === false ? 'TINVAR_ENV' : "TINVAR_ENV={$before}");
    }
});
