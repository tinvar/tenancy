<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * Which slugs a tenant may take (ADR 0155): a slug is the label of its
 * subdomain, `{slug}.example.com`, so it is held to what a label may be and
 * kept off every name the platform uses for itself.
 *
 * Reserved, whatever the application adds: `admin` and `www`, and the first
 * label of each of the platform's own hosts under its domain -- the staff's
 * `admin.`, an `api.`, a `status.`. Where the application routes by host, a
 * request to one of those is placed in no tenant anyway (ADR 0149), so a
 * tenant holding the slug would hold a subdomain that is never theirs; the
 * rule keeps it from being taken at all.
 */
final readonly class SlugRules
{
    /** Reserved always. */
    public const array RESERVED = ['admin', 'www'];

    /** @var array<string, true> */
    private array $reserved;

    /**
     * @param list<string> $reserved more slugs no tenant may take
     * @param list<string> $platformHosts the platform's own hosts, normalized; the first label
     *        of each under $root is reserved
     */
    public function __construct(array $reserved = [], array $platformHosts = [], string $root = '')
    {
        $all = [...self::RESERVED, ...array_map(strtolower(...), $reserved)];
        foreach ($platformHosts as $host) {
            if ($root !== '' && str_ends_with($host, ".{$root}")) {
                $labels = explode('.', substr($host, 0, -\strlen(".{$root}")));
                $all[] = end($labels);
            }
        }
        $this->reserved = array_fill_keys($all, true);
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
}
