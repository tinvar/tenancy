<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Carbon\CarbonImmutable;
use Tinvar\Data\Data;
use Tinvar\Tenancy\SelfService\TenantDomain;
use Tinvar\Tenancy\SelfService\TenantDomainStatus;

/**
 * One of the tenant's custom domains on its domains page: where it stands,
 * what its last check found, and the records to create for it.
 */
final readonly class TenantDomainData extends Data
{
    /** @param list<DnsRecordData> $records */
    public function __construct(
        public string $id,
        public string $host,
        public TenantDomainStatus $status,
        public string $reason,
        public ?CarbonImmutable $checkedAt,
        public array $records,
    ) {}

    /** @param list<DnsRecordData> $records */
    public static function of(TenantDomain $domain, array $records): self
    {
        return new self($domain->id, $domain->host, $domain->status, $domain->reason, $domain->checkedAt, $records);
    }
}
