<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\Fakes;

use Tinvar\Support\DevMode;

/**
 * The one check every tenancy fake makes as it is built: none is made in a
 * process that says production. A fake source names whatever tenant a test
 * gave it, and a fake switcher moves no session; wired into a server by
 * mistake, either would serve a tenant's data to a request nothing placed.
 *
 * @internal
 */
final class Guard
{
    /** @throws \LogicException where any production marker says production */
    public static function refuseInProduction(string $fake): void
    {
        $marker = DevMode::productionMarker(array_filter(getenv(), \is_string(...)));
        if ($marker !== null) {
            throw new \LogicException("{$fake} is a test's, and is refused: {$marker}=production.");
        }
    }
}
