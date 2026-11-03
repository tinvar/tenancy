<?php

declare(strict_types=1);

/**
 * A tenant's slug never names one of the platform's own hosts (ADR 0161):
 * `admin`, `api`, `www`, every label of a host its realms list or its
 * Domains route, and every panel only its own hosts carry.
 */

namespace Tinvar\Tests\Tenancy\SelfService\Slugs;

use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Routing\Domains;
use Tinvar\Tenancy\SelfService\SlugRules;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdminWorld;

require_once __DIR__ . '/Fixtures/self_service.php';

it('reserves admin, api and www whatever the application says', function () {
    $rules = new SlugRules();

    expect($rules->refusal('admin'))->not->toBeNull()
        ->and($rules->refusal('api'))->not->toBeNull()
        ->and($rules->refusal('www'))->not->toBeNull()
        ->and($rules->refusal('acme'))->toBeNull();
});

it('reserves every label of every host the platform lists or routes, and its own panels', function () {
    $realms = new Realms(
        Realm::platform('https://app.example.com'),
        [Realm::platform('https://billing.example.com', panel: 'billing'), Realm::platform('https://ops.eu.example.com', panel: 'staff')],
        platformPanels: ['staff'],
    );
    $routing = new Domains(
        ['root' => 'example.com', 'docs' => 'docs.example.com'],
        patterns: ['{tenant}.{root}', 'status.{root}', 'partners.{root}', '{tenant}.sandbox.{root}'],
    );
    $rules = SlugRules::forPlatform($realms, $routing, ['support']);

    foreach (['app', 'billing', 'ops', 'staff', 'status', 'partners', 'sandbox', 'docs', 'support', 'admin', 'api', 'www'] as $slug) {
        expect($rules->refusal($slug))->toBe("'{$slug}' is one of the platform's own addresses. Choose another.", $slug)
            ->and($rules->isReserved($slug))->toBeTrue();
    }
    expect($rules->refusal('acme'))->toBeNull()
        // A parameter is no label of the platform's.
        ->and($rules->isReserved('eu'))->toBeTrue()
        ->and($rules->isReserved('{}'))->toBeFalse()
        ->and($rules->isReserved('example'))->toBeFalse();

    // Without Domains, the realms' hosts alone, under the authority's domain.
    expect(SlugRules::forPlatform($realms)->isReserved('billing'))->toBeTrue()
        ->and(SlugRules::forPlatform($realms)->isReserved('status'))->toBeFalse();
});

it('refuses api as a slug on the settings page', function () {
    $world = new AdminWorld();

    expect($world->act('OrganizationSettings.changeSlug', ['slug' => 'api'])->status)->toBe(422)
        ->and($world->kit->slugs->isReserved('admin'))->toBeTrue()
        ->and($world->tenants->slugOf('acme'))->toBe('acme');
});
