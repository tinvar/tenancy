<?php

declare(strict_types=1);

/**
 * The names tenancy's classes had in the framework's Http and Console,
 * kept as deprecated aliases until 0.5 (ADR 0056); the tenant tree's names
 * under Tinvar\Orm, never released, kept as nothing.
 */

namespace Tinvar\Tests\Tenancy\Aliases;

use Tinvar\Tenancy\ResolveTenant;
use Tinvar\Tenancy\TenantArgument;
use Tinvar\Tenancy\TenantDirectory;
use Tinvar\Tenancy\Tenants;

it('aliases each old name to its class, saying it is deprecated', function (string $old, string $new) {
    $said = [];
    set_error_handler(static function (int $level, string $message) use (&$said): bool {
        $said[] = [$level, $message];

        return true;
    });
    try {
        $exists = class_exists($old) || interface_exists($old);
    } finally {
        restore_error_handler();
    }

    expect($exists)->toBeTrue()
        ->and(new \ReflectionClass($old)->getName())->toBe($new)
        ->and($said === [] || ($said[0][0] === \E_USER_DEPRECATED && str_contains($said[0][1], $new)))->toBeTrue();
})->with([
    'ResolveTenant' => ['Tinvar\Http\Middleware\ResolveTenant', ResolveTenant::class],
    'TenantArgument' => ['Tinvar\Console\TenantArgument', TenantArgument::class],
    'Tenants' => ['Tinvar\Console\Tenants', Tenants::class],
    'TenantDirectory' => ['Tinvar\Console\TenantDirectory', TenantDirectory::class],
]);

it('keeps no alias for the tenant tree\'s names under the ORM, which were never released', function () {
    expect(class_exists('Tinvar\Orm\TenantHierarchy'))->toBeFalse()
        ->and(interface_exists('Tinvar\Orm\TenantTree'))->toBeFalse();
});
