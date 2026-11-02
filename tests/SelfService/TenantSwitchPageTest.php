<?php

declare(strict_types=1);

/**
 * The ready-made tenant switcher (ADR 0155), through the real Kernel of a
 * host-routed application: m-1 belongs to acme and globex, on their
 * subdomains, and to initech, on its custom domain; umbrella is someone
 * else's. On a subdomain, the others are a handoff away; on the custom
 * domain, nothing else is offered; on the apex, with no addresses, a switch
 * is a new session. Every refusal is the one 403.
 */

namespace Tinvar\Tests\Tenancy\SelfService\Switcher;

use Tinvar\Tests\Tenancy\Fixtures\Domains\App;
use Tinvar\Tests\Tenancy\Fixtures\Domains\Sessions;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\RecordedActivity;

use function Tinvar\Tests\Tenancy\SelfService\Fixtures\selfServiceApp;

require_once __DIR__ . '/Fixtures/self_service.php';

/** @return array<string, mixed> */
function page(App $app, string $host, string $path = '/organizations'): array
{
    $response = $app->get($path, $host);
    expect($response->status)->toBe(200, $response->body());

    return App::json($response)['page'];
}

describe('on a tenant\'s subdomain', function () {
    it('lists the tenant it runs as first, and each other tenant of the user\'s on its own host', function () {
        $page = page(selfServiceApp(), 'acme.example.test');

        expect($page['current'])->toBe('acme')
            ->and($page['tenants'])->toBe([
                ['tenant' => 'acme', 'name' => 'acme', 'via' => 'current', 'host' => ''],
                ['tenant' => 'globex', 'name' => 'globex', 'via' => 'handoff', 'host' => 'globex.example.test'],
                ['tenant' => 'initech', 'name' => 'initech', 'via' => 'handoff', 'host' => 'initech.io'],
            ]);
    });

    it('switches to another tenant by handing off to its host, and records it', function () {
        $activity = new RecordedActivity();
        $app = selfServiceApp(activity: $activity);

        $response = $app->post('/_action/SwitchOrganization.switchTo', 'acme.example.test', ['tenant' => 'globex']);

        expect($response->status)->toBe(303, $response->body())
            ->and($response->header('location'))->toBe('https://globex.example.test/_handoff/start?return=%2F')
            ->and($activity->records)->toBe([['change' => 'tenant.switched', 'tenant' => 'globex', 'actor' => 'm-1', 'details' => ['from' => 'acme', 'via' => 'handoff']]])
            // No session was switched here: the handoff signs in on the other host.
            ->and($app->container->get(Sessions::class)->switched)->toBe([]);
    });

    it('hands off to a custom domain at its own origin', function () {
        $response = selfServiceApp()->post('/_action/SwitchOrganization.switchTo', 'acme.example.test', ['tenant' => 'initech']);

        expect($response->status)->toBe(303)
            ->and($response->header('location'))->toBe('https://initech.io/_handoff/start?return=%2F');
    });

    it('refuses a tenant the user does not belong to, and one that does not exist, alike', function (string $tenant) {
        $activity = new RecordedActivity();
        $response = selfServiceApp(activity: $activity)->post('/_action/SwitchOrganization.switchTo', 'acme.example.test', ['tenant' => $tenant]);

        expect($response->status)->toBe(403)
            ->and(App::json($response)['error']['code'] ?? App::json($response)['code'] ?? null)->toBe('tenant_switch_refused')
            ->and($response->body())->not->toContain($tenant)
            ->and($activity->records)->toBe([]);
    })->with(['someone else\'s' => ['umbrella'], 'nobody\'s' => ['no-such-tenant'], 'a platform host\'s slug' => ['tenant-admin']]);

    it('refuses anyone not signed in, before listing anything', function () {
        $app = selfServiceApp(global: []);

        expect($app->get('/organizations', 'acme.example.test')->status)->toBe(401)
            ->and($app->post('/_action/SwitchOrganization.switchTo', 'acme.example.test', ['tenant' => 'globex'])->status)->toBe(401);
    });

    it('refuses a host whose tenant the user is no member of, by the panel', function () {
        // umbrella is suspended, so its host is nobody's; tenant-www's is a platform host.
        expect(selfServiceApp()->get('/organizations', 'umbrella.example.test')->status)->toBe(404);
    });
});

describe('on a tenant\'s custom domain', function () {
    it('offers nothing on another host: the switcher there is held to the domain\'s subtree', function () {
        $page = page(selfServiceApp(), 'initech.io');

        expect($page['tenants'])->toBe([['tenant' => 'initech', 'name' => 'initech', 'via' => 'current', 'host' => '']]);
    });

    it('refuses a switch to a tenant the user belongs to elsewhere with the same 403', function () {
        $response = selfServiceApp()->post('/_action/SwitchOrganization.switchTo', 'initech.io', ['tenant' => 'acme']);

        expect($response->status)->toBe(403)
            ->and($response->body())->toContain('tenant_switch_refused');
    });
});

describe('on the platform\'s apex', function () {
    it('switches by a new session where tenants have no host of their own', function () {
        $activity = new RecordedActivity();
        $app = selfServiceApp(addressed: false, activity: $activity);

        expect(array_column(page($app, 'example.test')['tenants'], 'via', 'tenant'))->toBe(['acme' => 'session', 'globex' => 'session', 'initech' => 'session']);

        $response = $app->post('/_action/PlatformSwitch.switchTo', 'example.test', ['tenant' => 'globex']);

        expect($response->status)->toBe(303, $response->body())
            ->and($response->header('location'))->toBe('/notes')
            ->and($app->container->get(Sessions::class)->switched)->toBe(['globex'])
            ->and($activity->records[0]['details'])->toBe(['from' => '', 'via' => 'session']);
    });

    it('hands off to each tenant\'s host where it has one', function () {
        $page = page(selfServiceApp(), 'example.test');

        expect(array_column($page['tenants'], 'via', 'tenant'))->toBe(['acme' => 'handoff', 'globex' => 'handoff', 'initech' => 'handoff']);
    });
});
