<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Identity\DnsLookupFailed;
use Tinvar\Identity\DnsTextResolver;

/**
 * Whether a tenant proved a custom domain is theirs: a TXT record at
 * `_tinvar-challenge.<host>` carrying their claim's token (ADR 0159).
 *
 * Where a domain points is no proof of who owns it. A domain a tenant gave
 * up -- removed, or left when their account closed -- usually goes on
 * pointing at the platform, and whoever claimed it next would be served on
 * it, with its visitors' cookies and its certificate: the dangling-domain
 * takeover. So a domain goes into service only once a record only its
 * owner could publish names the token of this tenant's claim, and that is
 * asked anew whenever a deactivated domain is put back.
 *
 * Bounded as DomainVerifier is: one lookup, given $timeout seconds.
 */
final readonly class DomainOwnership
{
    /** The label the record sits under, in front of the domain. */
    public const string LABEL = '_tinvar-challenge';

    /** What the record's value starts with, before the token. */
    public const string PREFIX = 'tinvar-domain=';

    public function __construct(
        private DnsTextResolver $resolver,
        private float $timeout = 4.0,
    ) {
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('An ownership check is given some time.');
        }
    }

    /** The name the TXT record goes at. */
    public static function name(string $host): string
    {
        return self::LABEL . '.' . $host;
    }

    /** The value the TXT record carries. */
    public static function value(string $token): string
    {
        return self::PREFIX . $token;
    }

    /**
     * Null when the record is there with the token; otherwise what is
     * missing, in the platform's words for the tenant.
     */
    public function unproved(string $host, string $token): ?string
    {
        if (preg_match('/^[0-9a-f]{32}\z/', $token) !== 1) {
            throw new \LogicException('An ownership token is 32 hexadecimal characters.');
        }

        try {
            $texts = $this->resolver->texts(self::name($host), $this->timeout);
        } catch (DnsLookupFailed $e) {
            return 'The ownership record could not be looked up: ' . $e->getMessage();
        }

        foreach ($texts as $text) {
            if (hash_equals(self::value($token), trim($text))) {
                return null;
            }
        }

        return 'The TXT record at ' . self::name($host) . ' is not there yet, or does not carry this domain\'s token.';
    }
}
