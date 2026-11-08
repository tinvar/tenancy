<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Attributes\Schedule;

/**
 * Every five minutes, checks the tenants' custom domains that are due (ADR
 * 0198): each in service against its ownership record and its DNS, about
 * once a day, and each lapsed claim until it is released. An application
 * with the self-service kit's custom domains lists it among its scheduled
 * tasks; the kit registers the TenantDomainReverification it asks for. It
 * replaces the framework's VerifyCustomDomains for those domains, which
 * reads only where they point.
 *
 * Due is per domain, not per run: each domain's next check is booked when
 * it is checked, a day out less up to three hours by the domain, so the
 * checks spread over the day instead of meeting at one minute, and each run
 * has a few to do. A run stops after four minutes and leaves the rest to
 * the next; two runs at once claim each domain before checking it, so none
 * is checked twice.
 *
 * As every scheduled task does, it runs with no tenant and as nobody; each
 * domain is read across tenants and written, recorded and announced as its
 * own tenant. A domain whose check threw is left for a later run, and the
 * failures are thrown together at the end, for the scheduler to report. A
 * domain lapsing is not a failure: it is what the task is for.
 *
 * At minute 2 and every five after, off the hour other work crowds.
 */
#[Schedule('2-59/5 * * * *')]
final class ReverifyTenantDomains
{
    public function __invoke(TenantDomainReverification $reverification): void
    {
        $failures = $reverification->run()['failures'];

        if ($failures !== []) {
            $reasons = [];
            foreach ($failures as $host => $e) {
                $reasons[] = "{$host}: " . $e::class . ': ' . $e->getMessage();
            }

            throw new \RuntimeException('Some custom domains were not checked: ' . implode('; ', $reasons));
        }
    }
}
