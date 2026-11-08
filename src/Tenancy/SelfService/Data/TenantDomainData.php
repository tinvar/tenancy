<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Carbon\CarbonImmutable;
use Tinvar\Data\Data;
use Tinvar\Tenancy\SelfService\DomainLapse;
use Tinvar\Tenancy\SelfService\TenantDomain;
use Tinvar\Tenancy\SelfService\TenantDomainStatus;

/**
 * One of the tenant's custom domains on its domains page: where it stands,
 * what its last check found and when the next is, and the records to create
 * for it (ADR 0161, 0198).
 */
final readonly class TenantDomainData extends Data
{
    /**
     * @param ?CarbonImmutable $nextCheckAt when the schedule checks it next; null for a pending
     *        or released claim, which only "check now" checks
     * @param ?CarbonImmutable $checkableAt when "check now" next looks it up, rather than
     *        answering from the last check
     * @param ?CarbonImmutable $lapsesAt while failing, the last moment it is served if nothing
     *        changes
     * @param ?CarbonImmutable $releasesAt while lapsed, the moment after which its claim is
     *        released, if it is not proved again
     * @param ?DomainLapse $lapse while lapsed, why
     * @param list<DnsRecordData> $records
     */
    public function __construct(
        public string $id,
        public string $host,
        public TenantDomainStatus $status,
        public string $reason,
        public ?CarbonImmutable $checkedAt,
        public array $records,
        public ?CarbonImmutable $nextCheckAt = null,
        public ?CarbonImmutable $checkableAt = null,
        public ?CarbonImmutable $lapsesAt = null,
        public ?CarbonImmutable $releasesAt = null,
        public ?DomainLapse $lapse = null,
    ) {}

    /** @param list<DnsRecordData> $records */
    public static function of(
        TenantDomain $domain,
        array $records,
        ?CarbonImmutable $lapsesAt = null,
        ?CarbonImmutable $releasesAt = null,
        ?CarbonImmutable $checkableAt = null,
    ): self {
        return new self(
            $domain->id,
            $domain->host,
            $domain->status,
            $domain->reason,
            $domain->checkedAt,
            $records,
            $domain->nextCheckAt,
            $checkableAt,
            $lapsesAt,
            $releasesAt,
            $domain->lapse,
        );
    }
}
