<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\Fakes;

use Tinvar\Support\DevMode;

/**
 * The one check every tenancy fake makes as it is built: none is made in a
 * process that does not say it is a test's or a developer's (DevMode::
 * notDevelopment(), ADR 0213). A fake source names whatever tenant a test
 * gave it, and a fake switcher moves no session; wired into a server by
 * mistake, either would serve a tenant's data to a request nothing placed.
 *
 * @internal
 */
final class Guard
{
    /** @throws \LogicException unless TINVAR_ENV says local, development or testing, and nothing says production */
    public static function refuseInProduction(string $fake): void
    {
        $why = DevMode::notDevelopment();
        if ($why !== null) {
            throw new \LogicException("{$fake} is a test's, and is refused: {$why}.");
        }
    }
}
