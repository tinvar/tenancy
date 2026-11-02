<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * The application's TenantRole enum, read: its cases, a stored value as a
 * case, and what a role grants. A value no case has is no role, and grants
 * nothing.
 */
final readonly class TenantRoles
{
    /** @param class-string<TenantRole> $enum */
    public function __construct(public string $enum = DefaultTenantRole::class)
    {
        if (!enum_exists($enum) || !is_subclass_of($enum, TenantRole::class)) {
            throw new \InvalidArgumentException("{$enum} is not a role enum: a backed enum implementing " . TenantRole::class . '.');
        }
        if ($enum::cases() === []) {
            throw new \InvalidArgumentException("{$enum} has no cases, so no member could hold a role.");
        }
    }

    /** @return list<TenantRole> */
    public function all(): array
    {
        /** @var list<TenantRole> $cases */
        $cases = ($this->enum)::cases();

        return $cases;
    }

    public function of(?string $value): ?TenantRole
    {
        if ($value === null) {
            return null;
        }
        $case = ($this->enum)::tryFrom($value);

        return $case instanceof TenantRole ? $case : null;
    }

    /** @return list<string> */
    public function permissionsOf(?string $value): array
    {
        $role = $this->of($value);

        return $role === null ? [] : $role->permissions();
    }

    /**
     * Whether everything $role grants, $permissions hold too: who may hand a role out.
     *
     * @param list<string> $permissions
     */
    public function within(string $role, array $permissions): bool
    {
        $granted = $this->of($role);

        return $granted !== null && array_diff($granted->permissions(), $permissions) === [];
    }
}
