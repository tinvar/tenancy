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
spl_autoload_register(static function (string $class): void {
    $renamed = [
        'tinvar\\http\\middleware\\resolvetenant' => Tinvar\Tenancy\ResolveTenant::class,
        'tinvar\\console\\tenantargument' => Tinvar\Tenancy\TenantArgument::class,
        'tinvar\\console\\tenants' => Tinvar\Tenancy\Tenants::class,
        'tinvar\\console\\tenantdirectory' => Tinvar\Tenancy\TenantDirectory::class,
    ][strtolower(ltrim($class, '\\'))] ?? null;

    if ($renamed !== null) {
        @trigger_error("{$class} is deprecated since tinvar/tenancy 0.4 and is removed in 0.5; use {$renamed}.", \E_USER_DEPRECATED);
        class_alias($renamed, $class);
    }
});
