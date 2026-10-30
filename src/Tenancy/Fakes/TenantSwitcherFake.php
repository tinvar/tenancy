<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\Fakes;

use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Tenancy\TenantSwitching;

/**
 * A tenant switcher whose choices a test says, for an application's own
 * switch page and action: what each panel offers, whether anyone is signed
 * in, and every switch made, in order.
 *
 *     $switcher = TenantSwitcherFake::offering(['admin' => ['acme', 'globex']]);
 *     // ... the action under test calls $switcher->switch($request, 'globex', 'admin')
 *     expect($switcher->switches())->toBe([['tenant' => 'globex', 'panel' => 'admin', 'session' => ...]]);
 *
 * It refuses as the real one does -- 401 with nobody signed in, the one
 * 403 `tenant_switch_refused` for a tenant the panel does not offer -- and
 * hands back a new session id on each switch, never the same twice, so an
 * application that sets it is tested setting a new one. The memberships,
 * the realm's subtree and the session store are not consulted: those are
 * TenantSwitcher's own, held by its own tests.
 *
 * Refused in a process that says production (Guard).
 */
final class TenantSwitcherFake implements TenantSwitching
{
    /** @var list<array{tenant: string, panel: string, session: string}> */
    private array $switches = [];

    /** @param array<string, list<string>>|null $choices by panel; null for nobody signed in */
    private function __construct(private readonly ?array $choices)
    {
        Guard::refuseInProduction(self::class);
    }

    /** @param array<string, list<string>> $choices the tenants each panel offers, in order */
    public static function offering(array $choices): self
    {
        return new self($choices);
    }

    /** A switcher for a request nobody is signed in to: everything is a 401. */
    public static function signedOut(): self
    {
        return new self(null);
    }

    public function choices(Request $request, string $panel): array
    {
        return $this->offered($panel);
    }

    public function switch(Request $request, string $tenant, string $panel): string
    {
        if ($tenant === '' || !\in_array($tenant, $this->offered($panel), true)) {
            throw new HttpException(403, "The tenant switch is refused: '{$tenant}' is not offered for panel '{$panel}'.", 'tenant_switch_refused');
        }
        $session = bin2hex(random_bytes(16));
        $this->switches[] = ['tenant' => $tenant, 'panel' => $panel, 'session' => $session];

        return $session;
    }

    /** @return list<array{tenant: string, panel: string, session: string}> every switch made, oldest first */
    public function switches(): array
    {
        return $this->switches;
    }

    /** @return list<string> */
    private function offered(string $panel): array
    {
        if ($this->choices === null) {
            throw new HttpException(401, 'A tenant is switched by someone signed in.', 'unauthenticated');
        }

        return $this->choices[$panel] ?? [];
    }
}
