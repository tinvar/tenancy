<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\HostName;
use Tinvar\Http\HttpException;
use Tinvar\Identity\Realms;
use Tinvar\Tenancy\SelfService\Data\DnsRecordData;
use Tinvar\Tenancy\SelfService\Data\TenantDomainData;
use Tinvar\Validation\ValidationException;

/**
 * A tenant's custom domains, as its admins add, check and remove them on
 * the domains page (ADR 0161), over TenantDomains and the
 * TenantDomainReverification that proves them, first and again (ADR 0198).
 *
 * Adding a domain claims it, and serves nothing: it is pending, and the
 * page shows the two records to create -- a TXT record proving the domain
 * is the tenant's (DomainOwnership), and a CNAME (or A and AAAA records)
 * pointing it at the platform. A check of a pending domain asks both, and
 * puts it in service once both hold and no other tenant holds the host.
 * From then on the re-verification checks both again -- on the schedule,
 * when the page is asked to, and where it points at every certificate
 * Caddy asks for -- marks it failing while its record is missing, and
 * lapses it past the grace, or at once when its DNS points elsewhere. A
 * lapsed domain is put back by proving it again, as a pending one is.
 *
 * Refused, with a message for the field saying what to do:
 *
 * - anything that is not a host name, an address, a single label;
 * - **a platform host**: one of the application's own (Realms), or a name
 *   at or under the platform's domains -- a tenant's custom domain is never
 *   a subdomain of the platform's, whose certificate and cookies are the
 *   platform's;
 * - **a host another tenant holds** -- in service -- said without naming
 *   whose, unless that tenant's ownership record is missing: then the
 *   host may be claimed, and a proof takes it over (ADR 0198);
 * - one the tenant has already, and one past the tenant's bound.
 */
final readonly class TenantDomainClaims
{
    /** The most domains a tenant may have, unless the application says otherwise. */
    public const int DEFAULT_LIMIT = 5;

    /**
     * Seconds after a check during which the page's "check now" answers
     * from it, with no lookup: one check a minute per domain, whoever asks,
     * on every worker -- the throttle is the check's own recorded time.
     */
    public const int RECHECK_AFTER = 60;

    /** @var list<string> */
    private array $platformDomains;

    /**
     * @param list<string> $targets the platform's CNAME targets, as the verifier has them
     * @param list<string> $addresses the platform's addresses, as the verifier has them: each
     *        single address is shown as an A or AAAA record to create; a prefix is not one
     * @param list<string> $platformDomains the platform's own domains, normalized: no tenant's
     *        domain may be one, or be under one
     * @param int $limit the most domains one tenant may have, pending ones included
     */
    public function __construct(
        private TenantDomains $domains,
        private TenantDomainReverification $reverification,
        private array $targets,
        private array $addresses,
        array $platformDomains,
        private ?Realms $realms = null,
        private int $limit = self::DEFAULT_LIMIT,
        private TenantActivity $activity = new UnrecordedTenantActivity(),
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException('A tenant may have at least one custom domain; to offer none, do not declare the domains page.');
        }
        $normalized = [];
        foreach ($platformDomains as $domain) {
            $host = HostName::normalize($domain);
            if ($host === null || $host !== $domain) {
                throw new \InvalidArgumentException("'{$domain}' is not a platform domain: a host name, normalized.");
            }
            $normalized[] = $host;
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('Name the platform\'s own domains, which no tenant may claim a custom domain at or under.');
        }
        $this->platformDomains = $normalized;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /** @return list<TenantDomain> */
    public function of(string $tenant): array
    {
        return $this->domains->forTenant($tenant);
    }

    /**
     * Claims $input for $tenant: a pending domain, and the records to create.
     *
     * @throws ValidationException under `host`, saying why it is refused
     */
    public function add(string $tenant, string $input, ?Authenticatable $actor): TenantDomain
    {
        $host = $this->claimable($tenant, $input);
        foreach ($this->domains->forTenant($tenant) as $domain) {
            if ($domain->host === $host) {
                throw self::refused("{$host} is already one of your domains.");
            }
        }

        // Counted under a lock a second add of the tenant's waits on: two at
        // once cannot both count the other's uncommitted row as absent.
        $domain = $this->domains->addWithin($tenant, $host, $this->limit)
            ?? throw self::refused("Your organization has {$this->limit} domains, the most it may have. Remove one to add another.");
        $this->activity->record(TenantChange::DomainAdded, $tenant, $actor, ['host' => $host]);

        return $domain;
    }

    /**
     * Checks one of the tenant's domains now -- the page's "check now" --
     * and records what was found: a pending or lapsed one proved as it was
     * first proved, one in service against its ownership record and its DNS
     * as the schedule checks it (TenantDomainReverification).
     *
     * @throws HttpException 404 for a domain that is not the tenant's
     */
    public function check(string $tenant, string $id, ?Authenticatable $actor): TenantDomain
    {
        $domain = $this->domains->ofTenant($tenant, $id) ?? throw self::notFound();

        // A check is a few DNS lookups of up to seconds each: one asked for
        // again within the minute is answered from the last.
        if ($this->checkableAt($domain)?->greaterThan($this->domains->now()) === true) {
            return $domain;
        }

        $this->reverification->check($domain, $actor);

        return $this->reread($tenant, $id);
    }

    /** When "check now" next looks the domain up rather than answering from its last check. */
    public function checkableAt(TenantDomain $domain): ?\Carbon\CarbonImmutable
    {
        return $domain->checkedAt?->addSeconds(self::RECHECK_AFTER);
    }

    /** One domain as its page shows it: its state, its schedule and its records. */
    public function describe(TenantDomain $domain): TenantDomainData
    {
        return TenantDomainData::of(
            $domain,
            array_map(DnsRecordData::of(...), $this->records($domain)),
            lapsesAt: $this->reverification->lapsesAt($domain),
            releasesAt: $this->reverification->releasesAt($domain),
            checkableAt: $this->checkableAt($domain),
        );
    }

    /**
     * Removes one of the tenant's domains: no longer served.
     *
     * @throws HttpException 404 for a domain that is not the tenant's
     */
    public function remove(string $tenant, string $id, ?Authenticatable $actor): void
    {
        $domain = $this->domains->ofTenant($tenant, $id) ?? throw self::notFound();
        $this->domains->remove($tenant, $id);
        $this->activity->record(TenantChange::DomainRemoved, $tenant, $actor, ['host' => $domain->host]);
    }

    /**
     * The records the tenant creates for $domain: ownership first, then
     * pointing -- a CNAME to the platform's target, or its addresses.
     *
     * @return list<DnsRecord>
     */
    public function records(TenantDomain $domain): array
    {
        $records = [new DnsRecord('TXT', DomainOwnership::name($domain->host), DomainOwnership::value($domain->token), 'ownership')];
        if ($this->targets !== []) {
            $records[] = new DnsRecord('CNAME', $domain->host, $this->targets[0], 'pointing');

            return $records;
        }
        foreach ($this->addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $records[] = new DnsRecord('A', $domain->host, $address, 'pointing');
            } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $records[] = new DnsRecord('AAAA', $domain->host, $address, 'pointing');
            }
        }

        return $records;
    }

    /**
     * $input as a host a tenant may claim, or a refusal saying why not.
     *
     * @throws ValidationException
     */
    private function claimable(string $tenant, string $input): string
    {
        $typed = strtolower(trim($input));
        $host = HostName::normalize($typed);
        if ($host === null || $host !== rtrim($typed, '.') || str_contains($typed, ':')) {
            throw self::refused('Enter a domain name, such as shop.example.com: no https://, no path and no port.');
        }
        if (!str_contains($host, '.')) {
            throw self::refused('Enter a full domain name, such as shop.example.com.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw self::refused('A domain on localhost cannot be served to anyone else. Enter one you own.');
        }

        foreach ($this->platformDomains as $platform) {
            if ($host === $platform || str_ends_with($host, ".{$platform}")) {
                throw self::refused("{$host} is one of the platform's own addresses. Enter a domain you own.");
            }
        }
        // The application's realms answer for its own hosts, and for custom
        // domains it may keep beside this table: another tenant's is held.
        $realm = $this->realms?->forHost($host);
        if ($realm !== null && !$realm->isCustom()) {
            throw self::refused("{$host} is one of the platform's own addresses. Enter a domain you own.");
        }

        // Held by another tenant: refused, unless its holder's ownership
        // record is missing, when a proof by this tenant may take it over.
        if ($this->reverification->heldElsewhere($tenant, $host) && !$this->reverification->contestable($tenant, $host)) {
            throw self::refused("{$host} is already in use. If it is yours, contact support.");
        }

        return $host;
    }

    /** The domain as a check left it, read again. */
    private function reread(string $tenant, string $id): TenantDomain
    {
        $domain = $this->domains->ofTenant($tenant, $id);
        if ($domain === null) {
            throw self::notFound();
        }

        return $domain;
    }

    private static function refused(string $message): ValidationException
    {
        return new ValidationException(['host' => [$message]]);
    }

    private static function notFound(): HttpException
    {
        return new HttpException(404, 'That domain is not one of your organization\'s.', 'not_found');
    }
}
