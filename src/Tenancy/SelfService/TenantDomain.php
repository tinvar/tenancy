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
     * @param ?CarbonImmutable $failingSince when the unbroken run of checks that could not
     *        read where it points began (CustomDomainVerification's grace)
     * @param ?CarbonImmutable $unprovedSince when the unbroken run of checks that found its
     *        ownership record missing began (ADR 0198); a lookup that failed neither starts
     *        nor ends one
     * @param ?CarbonImmutable $unreadableSince when the unbroken run of checks that could not
     *        read its ownership record at all began: a run of its own, lapsing past the
     *        pointing grace, so a name whose lookups are made to fail is not served forever
     * @param ?CarbonImmutable $nextCheckAt when the schedule checks it next; null for a pending
     *        or released claim, which it does not check, or one never booked, which is due
     * @param ?DomainLapse $lapse why it went out of service, while Deactivated
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
        public ?CarbonImmutable $unprovedSince = null,
        public ?CarbonImmutable $nextCheckAt = null,
        public ?DomainLapse $lapse = null,
        public ?CarbonImmutable $unreadableSince = null,
    ) {}

    /** The record the framework's re-verification reads (CustomDomainVerification, TlsAskEndpoint). */
    public function custom(): CustomDomain
    {
        return new CustomDomain($this->host, $this->status->active(), $this->checkedAt, $this->failingSince);
    }
}
