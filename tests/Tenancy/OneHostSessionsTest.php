<?php

declare(strict_types=1);

/**
 * Every tenant on one host (ADR 0181), through the real Kernel: the
 * accounts kit's session holds the tenant its user switched to, through
 * TenantSwitcher and AccountTenantSessions; StartSession runs each request
 * as it; the panel's membership check asks in it on every request; and an
 * entry point reading its tenant from the host holds the session's to it.
 */

namespace Tinvar\Tests\Tenancy\OneHost;

use Tinvar\Accounts\SessionState;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Tenancy\AccountTenantSessions;
use Tinvar\Tests\Accounts\Fixtures\Members;
use Tinvar\Tests\Tenancy\Fixtures\OneHost\App;

require_once __DIR__ . '/Fixtures/one_host_app.php';

it('switches tenant on one host under a new session, and runs every request after it as the tenant chosen', function () {
    $app = new App();
    $cookie = $app->member('ann@example.com');
    $app->roster->tenants[$app->idOf('ann@example.com')] = ['acme', 'globex'];

    // Signed in, no tenant chosen yet: a member of the panel in none.
    expect($app->get('/notes', $cookie)->status)->toBe(403);

    $switched = $app->switchTo('acme', $cookie);
    $acme = $app->cookieOf($switched);
    expect($switched->status)->toBe(303, $switched->body())
        ->and($switched->header('location'))->toBe('/notes')
        ->and($acme)->toBeString()->not->toBe($cookie);
    \assert(\is_string($acme));

    $page = $app->get('/notes', $acme);
    expect($page->status)->toBe(200, $page->body())
        ->and(App::json($page))->toBe(['tenant' => 'acme', 'bodies' => ['acme-note-1', 'acme-note-2']])
        // The id before the switch signs nobody in: its tokens end with it.
        ->and($app->get('/notes', $cookie)->status)->toBe(401)
        // And the worker runs as no tenant between requests.
        ->and($app->orm->tenant())->toBe('');

    $globex = $app->cookieOf($app->switchTo('globex', $acme));
    \assert(\is_string($globex));
    expect(App::json($app->get('/notes', $globex)))->toBe(['tenant' => 'globex', 'bodies' => ['globex-note-1', 'globex-note-2']])
        ->and($app->get('/notes', $acme)->status)->toBe(401);
});

it('refuses a switch to a tenant the user is no member of, and keeps the session', function () {
    $app = new App();
    $cookie = $app->member('ann@example.com');
    $app->roster->tenants[$app->idOf('ann@example.com')] = ['acme'];
    $acme = $app->cookieOf($app->switchTo('acme', $cookie));
    \assert(\is_string($acme));

    $refused = $app->switchTo('umbrella', $acme);
    expect($refused->status)->toBe(403)
        ->and($refused->body())->toContain('tenant_switch_refused')
        ->and($app->cookieOf($refused))->toBeNull()
        ->and(App::json($app->get('/notes', $acme))['tenant'])->toBe('acme');

    // Nobody signed in has nothing to switch.
    expect($app->switchTo('acme', $app->accounts->kit->cookie->issue())->status)->toBe(401);
});

// The session's tenant is a choice; the panel's membership is the authority.
it('refuses a member removed from the tenant after switching to it, by the panel, at their next request', function () {
    $app = new App();
    $cookie = $app->member('ann@example.com');
    $id = $app->idOf('ann@example.com');
    $app->roster->tenants[$id] = ['acme', 'globex'];
    $acme = $app->cookieOf($app->switchTo('acme', $cookie));
    \assert(\is_string($acme));
    expect($app->get('/notes', $acme)->status)->toBe(200);

    $app->roster->tenants[$id] = ['globex'];
    $refused = $app->get('/notes', $acme);
    expect($refused->status)->toBe(403)
        ->and($refused->body())->not->toContain('acme-note');
});

// "The tenant resolved from a host only constrains."
it('holds a session\'s tenant to the host\'s on an entry point that reads its tenant from the host', function () {
    $app = new App();
    $cookie = $app->member('ann@example.com', 'acme.test');
    $app->roster->tenants[$app->idOf('ann@example.com')] = ['acme', 'globex'];

    expect(App::json($app->get('/host/notes', $cookie, 'acme.test'))['tenant'])->toBe('acme');

    $globex = $app->cookieOf($app->switchTo('globex', $cookie, 'acme.test'));
    \assert(\is_string($globex));
    expect($app->get('/host/notes', $globex, 'acme.test')->status)->toBe(404);

    $acme = $app->cookieOf($app->switchTo('acme', $globex, 'acme.test'));
    \assert(\is_string($acme));
    expect(App::json($app->get('/host/notes', $acme, 'acme.test')))->toBe(['tenant' => 'acme', 'bodies' => ['acme-note-1', 'acme-note-2']]);
});

it('switches only the session of the user switching, and refuses one that has ended', function () {
    $app = new App();
    $cookie = $app->member('ann@example.com');
    $app->member('bob@example.com');
    $kit = $app->accounts->kit;
    $request = new Request('POST', '/_action/x', ['host' => 'app.test'], cookies: [$kit->cookie->name => $cookie]);
    $state = $kit->sessions->open($request);
    $request = $request->withAttribute(SessionState::ATTRIBUTE, $state);
    $sessions = new AccountTenantSessions($kit->sessions);
    $bob = new Members($app->orm)->findByEmail('bob@example.com');
    \assert($bob !== null);

    $refusal = static function (\Closure $switch): ?int {
        try {
            $switch();
        } catch (HttpException $e) {
            return $e->status;
        }

        return null;
    };
    expect($refusal(static fn () => $sessions->switchTo($request, $bob, 'acme')))->toBe(403);

    $app->orm->connection()->execute('DELETE FROM sessions');
    $ann = $state->user();
    \assert($ann !== null);
    expect($refusal(static fn () => $sessions->switchTo($request, $ann, 'acme')))->toBe(401)
        ->and($state->replacement())->toBeNull();
});
