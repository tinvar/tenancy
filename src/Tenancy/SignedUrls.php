<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Http\HostName;
use Tinvar\Http\Request;
use Tinvar\Routing\HostMatch;
use Tinvar\Support\Signer;

/**
 * URLs that carry their tenant under a MAC: what #[TenantFrom(Signature::class)]
 * reads.
 *
 * For a request that arrives with no session to say whose it is -- a link in
 * an email, an export download, a webhook a tenant registered -- the URL
 * itself has to. It names the tenant in its query and is signed over the
 * path and the whole query, so the tenant cannot be swapped and nothing
 * else in the query can be either: an unsubscribe link for one list does
 * not become one for another by editing a parameter.
 *
 * Every link expires. A link mailed out is copied, forwarded and indexed,
 * and one that never expired would be a standing credential for a tenant's
 * data in whoever's inbox it ended up in.
 *
 * The method is not signed: a link is followed with GET, and a webhook's
 * URL is configured once and POSTed to. A route that must refuse one of
 * those says so with the methods it answers.
 *
 * The host is (ADR 0149): every token is its host's, and a signed URL, which
 * names its tenant with no session behind it, more than most. Where the
 * application routes by host, a URL is signed for the host it is opened on
 * (`sign(..., host:)`) and verified against the host the Kernel resolved
 * for the request, so a link signed on acme's subdomain opens nothing on
 * globex's. One signed for no host -- every URL signed before the host was
 * -- verifies only where the application routes on no host, and is refused
 * wherever it does: such a link is signed again, on its host.
 */
final readonly class SignedUrls
{
    /** The Signer purpose; a signed URL's MAC verifies as nothing else. */
    public const string PURPOSE = 'signed-url';

    public const string TENANT = 'tenant';

    public const string EXPIRES = 'expires';

    public const string SIGNATURE = 'signature';

    /** A week: long enough for an emailed link to be opened, short enough to stop being one. */
    public const int DEFAULT_TTL = 604800;

    /** @param Signer $signer built for PURPOSE from the application key */
    public function __construct(private Signer $signer) {}

    /**
     * A same-origin URL for $path, as $tenant, valid for $ttl seconds.
     *
     * @param array<string, string|int> $query the rest of the query, signed with it
     * @param string $host the host it is opened on, where the application routes by host --
     *        as the Kernel resolves it, without a port; '' for none, where it routes on none
     */
    public function sign(string $path, string $tenant, array $query = [], int $ttl = self::DEFAULT_TTL, ?int $now = null, string $host = ''): string
    {
        if ($host !== '') {
            $named = str_contains($host, ':') ? null : (HostName::normalize($host) ?? (filter_var($host, FILTER_VALIDATE_IP) === false ? null : $host));
            if ($named === null) {
                throw new \InvalidArgumentException("'{$host}' is not a host a signed URL can be opened on: a host name or an address, with no port.");
            }
            $host = $named;
        }
        if ($tenant === '') {
            throw new \InvalidArgumentException('A signed URL names a tenant; an empty one would be no tenant at all.');
        }
        if (!str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new \InvalidArgumentException('A signed URL is a path on this site, with its query given separately.');
        }
        if ($ttl < 1) {
            throw new \InvalidArgumentException('A signed URL has to be valid for at least a second.');
        }
        foreach ([self::TENANT, self::EXPIRES, self::SIGNATURE] as $reserved) {
            if (\array_key_exists($reserved, $query)) {
                throw new \InvalidArgumentException("'{$reserved}' is the signature's own parameter.");
            }
        }
        foreach (array_keys($query) as $name) {
            // PHP reads `a.b` and `a b` back as `a_b`, and `a[b]` as an
            // array: a URL signed with such a name could never verify.
            if (preg_match('/^[A-Za-z0-9_-]+\z/', (string) $name) !== 1) {
                throw new \InvalidArgumentException("'{$name}' would not survive being parsed back out of the query.");
            }
        }

        $query = array_map('strval', $query);
        $query[self::TENANT] = $tenant;
        $query[self::EXPIRES] = (string) (($now ?? time()) + $ttl);
        $query[self::SIGNATURE] = Signer::encode($this->signer->sign(self::payload($path, $query, $host)));

        return $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The tenant a request's URL was signed for, or null: unsigned, edited,
     * expired, signed with another key, or signed for another host than the
     * one the Kernel resolved for it -- no host, where it resolved one.
     */
    public function tenantOf(Request $request, ?int $now = null): ?string
    {
        $query = $request->query;
        $tenant = $query[self::TENANT] ?? null;
        $expires = $query[self::EXPIRES] ?? null;
        $signature = $query[self::SIGNATURE] ?? null;

        if (!\is_string($tenant) || $tenant === '' || !\is_string($expires) || !\is_string($signature)
            || preg_match('/^[1-9]\d{0,18}\z/', $expires) !== 1) {
            return null;
        }

        // The signature first, then the expiry: an edited expiry is a
        // forgery, and is reported as one rather than as a lapse.
        $mac = Signer::decode($signature);
        if ($mac === null || !$this->signer->verify(self::payload($request->path, $query, HostMatch::hostOf($request)), $mac)) {
            return null;
        }

        return (int) $expires > ($now ?? time()) ? $tenant : null;
    }

    /**
     * What is signed: the host, where there is one, the path and every
     * query parameter but the signature, by name, so the order a client
     * sends them in does not matter and adding one is an edit like any
     * other. With no host, exactly what was signed before the host was: a
     * path starts with `/`, so no payload with a host is one without.
     *
     * @param array<string, mixed> $query
     */
    private static function payload(string $path, array $query, string $host = ''): string
    {
        unset($query[self::SIGNATURE]);
        ksort($query, SORT_STRING);

        return ($host === '' ? '' : 'host:' . $host . "\n") . $path . "\n" . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
