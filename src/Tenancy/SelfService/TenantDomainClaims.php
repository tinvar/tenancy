<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\HostName;
use Tinvar\Http\HttpException;
use Tinvar\Identity\CustomDomainVerification;
use Tinvar\Identity\DomainPointing;
use Tinvar\Identity\DomainVerifier;
use Tinvar\Identity\Realms;
use Tinvar\Validation\ValidationException;

/**
 * A tenant's custom domains, as its admins add, check and remove them on
 * the domains page (ADR 0159), over TenantDomains and the framework's
 * DomainVerifier and CustomDomainVerification.
 *
 * Adding a domain claims it, and serves nothing: it is pending, and the
 * page shows the two records to create -- a TXT record proving the domain
 * is the tenant's (DomainOwnership), and a CNAME (or A and AAAA records)
 * pointing it at the platform. A check of a pending domain asks both, and
 * puts it in service once both hold and no other tenant holds the host.
 * From then on it is the framework's re-verification that checks it --
 * daily, at every certificate Caddy asks for, and when the page is asked
 * to -- and deactivates it when its DNS points elsewhere, or cannot be
 * read for longer than the grace period. A deactivated domain is put back
 * by proving it again, as a pending one is.
 *
 * Refused, with a message for the field saying what to do:
 *
 * - anything that is not a host name, an address, a single label;
 * - **a platform host**: one of the application's own (Realms), or a name
 *   at or under the platform's domains -- a tenant's custom domain is never
 *   a subdomain of the platform's, whose certificate and cookies are the
 *   platform's;
 * - **a host another tenant holds** -- in service -- said without naming
 *   whose;
 * - one the tenant has already, and one past the tenant's bound.
 */
final readonly class TenantDomainClaims
{
    /** The most domains a tenant may have, unless the application says otherwise. */
    public const int DEFAULT_LIMIT = 5;

    /** Seconds after a check during which the page's "check now" answers from it. */
    public const int RECHECK_AFTER = 10;

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
        private DomainVerifier $verifier,
        private DomainOwnership $ownership,
        private CustomDomainVerification $verification,
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

        $domain = $this->domains->add($tenant, $host);
        // Counted after the insert, so two adds at once cannot both slip in
        // under the bound: each counts the other's, and is taken back.
        if ($this->domains->countForTenant($tenant) > $this->limit) {
            $this->domains->remove($tenant, $domain->id);

            throw self::refused("Your organization has {$this->limit} domains, the most it may have. Remove one to add another.");
        }
        $this->activity->record(TenantChange::DomainAdded, $tenant, $actor, ['host' => $host]);

        return $domain;
    }

    /**
     * Checks one of the tenant's domains now, and records what was found: a
     * pending or deactivated one against its ownership record and its DNS,
     * one in service as the daily re-verification does.
     *
     * @throws HttpException 404 for a domain that is not the tenant's
     */
    public function check(string $tenant, string $id, ?Authenticatable $actor): TenantDomain
    {
        $domain = $this->domains->ofTenant($tenant, $id) ?? throw self::notFound();
        $now = $this->domains->now();

        // A check is a few DNS lookups of up to seconds each: one asked for
        // again at once is answered from the last.
        if ($domain->checkedAt !== null && $now->getTimestamp() - $domain->checkedAt->getTimestamp() < self::RECHECK_AFTER) {
            return $domain;
        }

        if ($domain->status->active()) {
            $this->verification->verify($domain->custom());

            return $this->reread($tenant, $id);
        }

        $missing = $this->ownership->unproved($domain->host, $domain->token);
        $pointing = $this->verifier->check($domain->host);
        if ($missing === null && $pointing->pointing !== DomainPointing::Platform) {
            $missing = "It does not point at the platform yet: {$pointing->reason}.";
        }
        if ($missing !== null) {
            $this->domains->unproved($tenant, $id, $now, $missing);
        } elseif ($this->heldElsewhere($tenant, $domain->host) || !$this->domains->activate($tenant, $id, $now, 'It is yours, and it points at the platform.')) {
            $this->domains->unproved($tenant, $id, $now, 'Another organization uses this domain. If it is yours, contact support.');
        } else {
            $this->activity->record(TenantChange::DomainVerified, $tenant, $actor, ['host' => $domain->host]);
        }

        return $this->reread($tenant, $id);
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

        if ($this->heldElsewhere($tenant, $host)) {
            throw self::refused("{$host} is already in use. If it is yours, contact support.");
        }

        return $host;
    }

    /**
     * Whether another tenant is served on $host: by this table, or by a
     * custom-domain realm the application keeps beside it.
     */
    private function heldElsewhere(string $tenant, string $host): bool
    {
        $realm = $this->realms?->forHost($host);
        $holder = $this->domains->tenantForHost($host) ?? ($realm !== null && $realm->isCustom() ? $realm->tenant : null);

        return $holder !== null && $holder !== $tenant;
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
