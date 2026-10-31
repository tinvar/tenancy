<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Orm\TenantBounds;
use Tinvar\Orm\TenantSubtrees;

/**
 * The application's TenantTree, read with limits: the whole of a subtree,
 * and the tenants above one -- whether one tenant is in another's subtree
 * is the Orm's to answer from them (TenantBounds::contains()).
 *
 * Both fail closed. A walk up from a tenant that loops, or goes deeper than
 * any real hierarchy would, says the tenant is not under the root -- a
 * request refused, never one let in on a tree that does not hold together.
 * A subtree larger than a read can carry is an error rather than a part of
 * one, since a part would be a silently wrong answer.
 *
 * It is the tree tenancy registers with the Orm, as the Orm's
 * TenantSubtrees: an application writes the tree, never the walk. The Orm
 * reads it through TenantBounds, which holds its answers to the same limits
 * again, so they hold for any implementation of the port, this one or an
 * application's own (ADR 0056).
 */
final readonly class TenantHierarchy implements TenantSubtrees
{
    /** Levels walked up from a tenant before it is taken to be under nothing. */
    public const int MAX_DEPTH = TenantBounds::MAX_DEPTH;

    /** Tenants in one subtree, root included, beyond which a read across it is refused. */
    public const int MAX_SUBTREE = TenantBounds::MAX_SUBTREE;

    public function __construct(private TenantTree $tree) {}

    /** A tree is a hierarchy, even one whose every tenant is a root. */
    public function hierarchical(): bool
    {
        return true;
    }

    /**
     * Every tenant above $tenant, its parent first, walked up one parent
     * at a time as it is read: the Orm's contains() reads it as far as the
     * root it asks about, so a check costs the tenant's depth below that
     * root, never the size of its subtree nor the chain above it; the
     * query cache reads it whole, for the tenants whose reads across their
     * subtree a write made as $tenant has to reach.
     *
     * Stopped at a loop, or past MAX_DEPTH, keeping what it found before:
     * an answer that reaches too few tenants leaves a page stale, and
     * never shows anyone a row.
     *
     * @return \Generator<int, string>
     */
    public function ancestors(string $tenant): \Generator
    {
        $seen = [$tenant => true];
        $current = $tenant;
        for ($depth = 0; $tenant !== '' && $depth < self::MAX_DEPTH; $depth++) {
            $parent = $this->tree->parentOf($current);
            if ($parent === null || $parent === '' || isset($seen[$parent])) {
                return;
            }
            $seen[$parent] = true;
            yield $parent;
            $current = $parent;
        }
    }

    /**
     * $root and every tenant below it, $root first.
     *
     * @return non-empty-list<string>
     * @throws \LengthException when the subtree is larger than MAX_SUBTREE
     */
    public function subtree(string $root): array
    {
        if ($root === '') {
            throw new \InvalidArgumentException('A subtree has a root; the empty tenant is no tenant.');
        }

        $tenants = [$root => true];
        foreach ($this->tree->descendantsOf($root) as $descendant) {
            if ($descendant !== '') {
                $tenants[$descendant] = true;
            }
        }

        if (\count($tenants) > self::MAX_SUBTREE) {
            throw new \LengthException(\sprintf(
                "Tenant '%s' has %d tenants in its subtree, more than the %d a read across one carries.",
                $root,
                \count($tenants),
                self::MAX_SUBTREE,
            ));
        }

        return array_map('strval', array_keys($tenants));
    }
}
