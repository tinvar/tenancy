<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\ListOf;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Http\HostName;
use Tinvar\Outbound\Cidr;
use Tinvar\Support\DevMode;

/**
 * Where tenants' custom domains must point, and how they are kept (ADR
 * 0161), read from `TINVAR_CUSTOM_DOMAIN_*` as an application's variables
 * are (ADR 0085):
 *
 * - `TARGETS`: the host names a domain may be a CNAME of, such as
 *   `domains.example.com` -- the first is the record the page shows;
 * - `ADDRESSES`: the addresses and prefixes its A and AAAA records may
 *   name, for a domain at a zone's apex, which can have no CNAME;
 * - `PLATFORM`: the platform's own domains, which no tenant may claim a
 *   domain at or under; the application's primary host's unless set;
 * - `GRACE`: seconds a domain whose DNS cannot be read stays in service --
 *   three days unless set;
 * - `LIMIT`: the most domains one tenant may have, five unless set.
 *
 * With neither targets nor addresses, nothing a tenant adds could ever
 * verify: in development, `domains.localhost` stands in, so the page works
 * and shows its records; in production, the application refuses to start,
 * naming what to set.
 */
#[Config(prefix: 'TINVAR_CUSTOM_DOMAIN_')]
final readonly class CustomDomainsConfig
{
    /** What a development app with nothing set points domains at: a name nothing on the internet resolves. */
    public const string DEVELOPMENT_TARGET = 'domains.localhost';

    public const int DEFAULT_GRACE = 259_200;

    /** @var list<string> */
    public array $targets;

    /**
     * @param list<string> $targets
     * @param list<string> $addresses
     * @param list<string> $platform
     */
    public function __construct(
        #[ListOf('string')]
        array $targets = [],
        #[ListOf('string')]
        public array $addresses = [],
        #[ListOf('string')]
        public array $platform = [],
        public int $grace = self::DEFAULT_GRACE,
        public int $limit = TenantDomainClaims::DEFAULT_LIMIT,
    ) {
        $problems = [];
        foreach ($targets as $target) {
            if (HostName::normalize($target) !== $target) {
                $problems[] = 'Each of TINVAR_CUSTOM_DOMAIN_TARGETS must be a host name, lowercase, with no port.';
            }
        }
        foreach ($addresses as $address) {
            try {
                Cidr::parse($address);
            } catch (\InvalidArgumentException) {
                $problems[] = 'Each of TINVAR_CUSTOM_DOMAIN_ADDRESSES must be an address or a prefix, such as 203.0.113.10 or 2001:db8::/48.';
            }
        }
        foreach ($platform as $domain) {
            if (HostName::normalize($domain) !== $domain) {
                $problems[] = 'Each of TINVAR_CUSTOM_DOMAIN_PLATFORM must be a host name, lowercase, with no port.';
            }
        }
        if ($grace < 0) {
            $problems[] = 'TINVAR_CUSTOM_DOMAIN_GRACE must be zero seconds or more.';
        }
        if ($limit < 1) {
            $problems[] = 'TINVAR_CUSTOM_DOMAIN_LIMIT must be at least 1.';
        }

        if ($targets === [] && $addresses === []) {
            $marker = DevMode::productionMarker(array_filter(getenv(), \is_string(...)));
            if ($marker !== null) {
                $problems[] = "Tenants add custom domains here, and {$marker}=production: set TINVAR_CUSTOM_DOMAIN_TARGETS to the host name "
                    . 'their CNAME records point at, or TINVAR_CUSTOM_DOMAIN_ADDRESSES to the platform\'s addresses, or both.';
            }
            $targets = [self::DEVELOPMENT_TARGET];
        }
        $this->targets = array_values(array_unique($targets));

        if ($problems !== []) {
            throw new ConfigException(array_values(array_unique($problems)));
        }
    }

    /** As the environment has it now. */
    public static function load(): self
    {
        return Configs::load(self::class);
    }
}
