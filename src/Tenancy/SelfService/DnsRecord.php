<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/** One DNS record a tenant creates for a custom domain: its type, where it goes, what it says, and why. */
final readonly class DnsRecord
{
    /**
     * @param string $type CNAME, A, AAAA or TXT
     * @param string $purpose `ownership` (proves the domain is the tenant's) or `pointing`
     *        (sends its visitors to the platform)
     */
    public function __construct(
        public string $type,
        public string $name,
        public string $value,
        public string $purpose,
    ) {}
}
