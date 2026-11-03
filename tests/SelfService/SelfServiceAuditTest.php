<?php

declare(strict_types=1);

/**
 * Audit probes of the settings page's rules (ADR 0161): what a change is
 * held to beyond the member permission.
 */

namespace Tinvar\Tests\Tenancy\SelfService\Audit;

use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdminWorld;

require_once __DIR__ . '/Fixtures/self_service.php';

it('refuses the only owner demoting themselves, which leaves no one able to rename the tenant or change its slug', function () {
    $world = new AdminWorld();

    // Ann is acme's only owner; Bob, an admin, carries tenant.members and tenant.domains but not tenant.settings.
    $response = $world->act('OrganizationSettings.changeRole', ['member' => 'm-1', 'role' => 'admin']);

    expect($response->status)->toBe(422, $response->body())
        ->and($world->members->roleOf('acme', 'customer', 'm-1'))->toBe('owner');
});
