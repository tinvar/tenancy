<?php

declare(strict_types=1);

/**
 * The names tenancy's classes had in the framework's own namespaces before
 * tenancy became a package of its own, kept for one release (ADR 0056).
 *
 * @deprecated since 0.4: use Tinvar\Tenancy\ResolveTenant, TenantArgument,
 *             Tenants and TenantDirectory. Removed in 0.5.
 *
 * An old name is aliased when something first asks for it, not when this
 * file loads, and is reported once per process as a deprecation. The alias
 * is the class itself, so `instanceof` and `implements` see one class, and
 * tenancy compares a command's declared source with is_a(), never by name:
 * `#[TenantFrom(\Tinvar\Console\TenantArgument::class)]` places a command as
 * the new name does.
 *
 * The tenant tree's names under Tinvar\Orm are not here: they were never
 * released, and Tinvar\Tenancy\TenantTree is the name it always had.
 *
 * It lives beside src/ rather than in it, because it names the framework's
 * namespaces on purpose: the layer rule reads src/ alone.
 */
$renamed = [
    'tinvar\\http\\middleware\\resolvetenant' => Tinvar\Tenancy\ResolveTenant::class,
    'tinvar\\console\\tenantargument' => Tinvar\Tenancy\TenantArgument::class,
    'tinvar\\console\\tenants' => Tinvar\Tenancy\Tenants::class,
    'tinvar\\console\\tenantdirectory' => Tinvar\Tenancy\TenantDirectory::class,
];

// Recorded whether or not an alias is ever made, so a refusal of an old
// name can say what it became (Tinvar\Support\KnownAttributes).
Tinvar\Support\RenamedClasses::record($renamed);

spl_autoload_register(static function (string $class) use ($renamed): void {
    $current = $renamed[strtolower(ltrim($class, '\\'))] ?? null;

    if ($current !== null) {
        @trigger_error("{$class} is deprecated since tinvar/tenancy 0.4 and is removed in 0.5; use {$current}.", \E_USER_DEPRECATED);
        class_alias($current, $class);
    }
});
