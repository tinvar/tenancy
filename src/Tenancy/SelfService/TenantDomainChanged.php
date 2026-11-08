<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Carbon\CarbonImmutable;

/**
 * A tenant's custom domain changed state by a check (ADR 0198): went
 * failing, was restored, lapsed, or had its lapsed claim released.
 * Dispatched through the application's Events as the tenant the domain is
 * -- whatever tenant the check ran in, or none on the schedule -- inside
 * the transaction that made the change, so an `afterCommit` listener hears
 * only a change that stood. The hook an application turns into a mail to
 * the tenant's admins:
 *
 *     #[Listener(afterCommit: true)]
 *     public function tell(TenantDomainChanged $change): void { ... }
 *
 * Plain values only: never the claim's token, which proves the claim to
 * whoever has it.
 */
final readonly class TenantDomainChanged
{
    /**
     * @param string $domain the domain's id
     * @param string $reason what the check found, in the platform's words
     * @param DomainLapse|null $lapse why it went out of service, when $to is Deactivated
     */
    public function __construct(
        public string $tenant,
        public string $domain,
        public string $host,
        public TenantDomainStatus $from,
        public TenantDomainStatus $to,
        public TenantChange $change,
        public string $reason,
        public ?DomainLapse $lapse,
        public CarbonImmutable $at,
    ) {}
}
