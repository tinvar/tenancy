<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Carbon\CarbonImmutable;
use Tinvar\Identity\CustomDomain;

/** One tenant's custom domain, as TenantDomains keeps it. */
final readonly class TenantDomain
{
    /**
     * @param string $token what the ownership TXT record must carry (DomainOwnership):
     *        this claim's alone, so another tenant's claim to the host is proved by nothing
     *        this one published
     * @param string $reason what the last check found, in the platform's words; '' for none
     */
    public function __construct(
        public string $id,
        public string $tenant,
        public string $host,
        public TenantDomainStatus $status,
        public string $token,
        public string $reason,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $checkedAt = null,
        public ?CarbonImmutable $verifiedAt = null,
        public ?CarbonImmutable $failingSince = null,
        public ?CarbonImmutable $deactivatedAt = null,
    ) {}

    /** The record the framework's re-verification reads (CustomDomainVerification, TlsAskEndpoint). */
    public function custom(): CustomDomain
    {
        return new CustomDomain($this->host, $this->status->active(), $this->checkedAt, $this->failingSince);
    }
}
