<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Identity\Realms;
use Tinvar\Routing\Domains;

/**
 * Which slugs a tenant may take (ADR 0161): a slug is the label of its
 * subdomain, `{slug}.example.com`, so it is held to what a label may be and
 * kept off every name the platform uses for itself.
 *
 * Reserved, whatever the application adds: `admin`, `api` and `www`; every
 * label of each of the platform's own hosts under its domain -- the staff's
 * `admin.`, an `api.`, a `status.`, both of `eu.api.` -- whether a realm
 * lists it or a #[Domain] routes it; and the name of each panel only the
 * platform's hosts carry. Where the application routes by host, a request
 * to one of those is placed in no tenant anyway (ADR 0149), so a tenant
 * holding the slug would hold a subdomain that is never theirs; the rule
 * keeps it from being taken at all, and from being taken now and shadowed
 * by a host the platform adds later.
 */
final readonly class SlugRules
{
    /** Reserved always. */
    public const array RESERVED = ['admin', 'api', 'www'];

    /** @var array<string, true> */
    private array $reserved;

    /**
     * @param list<string> $reserved more slugs no tenant may take
     * @param list<string> $platformHosts the platform's own hosts, normalized, or host patterns
     *        as Domains::hosts() writes them (`{}` for a parameter); every literal label of each
     *        under one of $roots is reserved
     * @param string|list<string> $roots the domains tenants' subdomains sit under
     */
    public function __construct(array $reserved = [], array $platformHosts = [], string|array $roots = '')
    {
        $roots = array_values(array_filter(\is_string($roots) ? [$roots] : $roots, static fn (string $root): bool => $root !== ''));
        // The most specific root first, so `x.eu.example.com` under both
        // `example.com` and `eu.example.com` reserves `x` alone.
        usort($roots, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        $all = [...self::RESERVED, ...array_map(strtolower(...), $reserved)];
        foreach ($platformHosts as $host) {
            foreach ($roots as $root) {
                if (str_ends_with($host, ".{$root}")) {
                    foreach (explode('.', substr($host, 0, -\strlen(".{$root}"))) as $label) {
                        if ($label !== '' && $label !== '{}') {
                            $all[] = $label;
                        }
                    }

                    break;
                }
            }
        }
        $this->reserved = array_fill_keys($all, true);
    }

    /**
     * The rules for an application: every host its realms list, every host
     * its Domains route (when it routes by host), and every panel only the
     * platform's hosts carry, under its configured domains -- or, with no
     * Domains, under the authority's.
     *
     * @param list<string> $reserved more slugs no tenant may take
     * @param list<string> $hosts more of the platform's own hosts: the targets tenants'
     *        custom domains are CNAMEs of (CustomDomainsConfig), whose labels under its
     *        domains are reserved as every other platform host's are (ADR 0220)
     */
    public static function forPlatform(Realms $realms, ?Domains $routing = null, array $reserved = [], array $hosts = []): self
    {
        $hosts = [...$realms->platformHosts(), ...$hosts];
        $roots = [self::rootOf($realms->authority->host)];
        if ($routing !== null) {
            $hosts = [...$hosts, ...$routing->hosts(), ...array_values($routing->roots)];
            $roots = [...array_values($routing->roots), ...$roots];
        }

        return new self([...$reserved, ...$realms->platformPanels()], $hosts, $roots);
    }

    /** Whether $slug is one of the platform's own names: what a sign-up holds a new tenant's to. */
    public function isReserved(string $slug): bool
    {
        return isset($this->reserved[strtolower($slug)]);
    }

    /** Null for a slug a tenant may take; otherwise why not, saying what to do. */
    public function refusal(string $slug): ?string
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])\z/', $slug) !== 1) {
            return 'Use 3 to 63 lowercase letters, digits and hyphens, starting and ending with a letter or digit.';
        }
        if (str_contains($slug, '--')) {
            return 'Use single hyphens: two together are kept for internationalized names.';
        }
        if (isset($this->reserved[$slug])) {
            return "'{$slug}' is one of the platform's own addresses. Choose another.";
        }

        return null;
    }

    /** The domain the platform's own host is under: `example.com` for `app.example.com`, the host itself for two labels. */
    private static function rootOf(string $host): string
    {
        $labels = explode('.', $host);

        return \count($labels) > 2 ? implode('.', \array_slice($labels, 1)) : $host;
    }
}
