<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Http\HttpException;
use Tinvar\Http\Request;

/**
 * What an application's switch page and action call: TenantSwitcher, or a
 * test's Fakes\TenantSwitcherFake in its place.
 *
 * Typed against this, the application's own page and action can be tested
 * without a membership table, a realm or a session store behind them; the
 * switcher's own rules are held by its own tests, against the real thing.
 */
interface TenantSwitching
{
    /**
     * The tenants the signed-in user may switch to here, holding a
     * membership of $panel in each.
     *
     * @return list<string>
     * @throws HttpException 401 for nobody signed in, 404 for a host that is no realm
     */
    public function choices(Request $request, string $panel): array;

    /**
     * Moves the request's session to $tenant.
     *
     * @return string the new session's id
     * @throws HttpException 401 for nobody signed in, 403 (`tenant_switch_refused`)
     *         for a tenant this user may not switch to here
     */
    public function switch(Request $request, string $tenant, string $panel): string;
}
