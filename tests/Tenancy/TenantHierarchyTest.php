<?php

declare(strict_types=1);

/**
 * TenantHierarchy: the application's TenantTree, walked within its limits,
 * which tenancy registers with the Orm as its TenantSubtrees.
 *
 * The Orm holds whatever it is given to the same limits again
 * (TenantBounds; the database's TreeBoundsTest), so these are the walk's
 * own: a tree that loops or runs deep says "not under", and a subtree too
 * large to read is refused rather than read in part.
 */

namespace Tinvar\Tests\Tenancy\TenantHierarchy;

use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\TenantBounds;
use Tinvar\Tenancy\TenantHierarchy;
use Tinvar\Tenancy\TenantTree;
use Tinvar\Tests\Tenancy\Fixtures\Tree;

require_once __DIR__ . '/Fixtures/tree.php';

/** A tree read through closures, for the shapes a real one must not take. */
function treeOf(\Closure $parentOf, \Closure $descendantsOf): TenantHierarchy
{
    return new TenantHierarchy(new readonly class ($parentOf, $descendantsOf) implements TenantTree {
        public function __construct(private \Closure $parentOf, private \Closure $descendantsOf) {}

        public function parentOf(string $tenant): ?string
        {
            return ($this->parentOf)($tenant);
        }

        public function descendantsOf(string $tenant): array
        {
            return ($this->descendantsOf)($tenant);
        }
    });
}

// Asked as the Orm asks it: through TenantBounds, the checked tree every
// caller of contains() is handed (Orm::tenants()).
it('places a tenant under its parent and every ancestor, and nowhere else', function () {
    $tenants = new TenantBounds(new TenantHierarchy(new Tree()));

    expect($tenants->contains('reseller', 'acme-west'))->toBeTrue()
        ->and($tenants->contains('acme', 'acme-west'))->toBeTrue()
        ->and($tenants->contains('reseller', 'reseller'))->toBeTrue()
        ->and($tenants->contains('acme', 'reseller'))->toBeFalse()
        ->and($tenants->contains('acme', 'globex'))->toBeFalse()
        ->and($tenants->contains('reseller', 'other'))->toBeFalse()
        ->and($tenants->contains('reseller', 'nobody'))->toBeFalse()
        ->and($tenants->contains('', 'acme'))->toBeFalse()
        ->and($tenants->contains('reseller', ''))->toBeFalse()
        ->and([...new TenantHierarchy(new Tree())->ancestors('acme-west')])->toBe(['acme', 'reseller']);
});

// Walked up from the tenant and stopped at the root asked about, so the
// answer costs the tenant's depth below it, not the size of the subtree
// nor the rest of the chain above. TenantHierarchy kept a contains() that
// did, which nothing called, while the checked tree walked every ancestor.
it('answers by walking up from the tenant, as far as the root and no further', function () {
    $tree = new Tree();
    $tenants = new TenantBounds(new TenantHierarchy($tree));

    $tenants->contains('acme', 'acme-west');
    $toParent = $tree->asked;
    $tenants->contains('reseller', 'acme-west');

    expect($toParent)->toBe(1)
        ->and($tree->asked - $toParent)->toBe(2)
        ->and(method_exists(TenantHierarchy::class, 'contains'))->toBeFalse();
});

it('refuses rather than admits on a tree that loops or goes deeper than any real one', function () {
    $loop = treeOf(static fn (string $t): string => $t === 'a' ? 'b' : 'a', static fn (): array => []);
    $deep = treeOf(static fn (string $t): ?string => $t === 'level-0' ? null : 'level-' . ((int) substr($t, 6) - 1), static fn (): array => []);

    expect(new TenantBounds($loop)->contains('root', 'a'))->toBeFalse()
        ->and([...$loop->ancestors('a')])->toBe(['b'])
        ->and(new TenantBounds($deep)->contains('level-0', 'level-' . TenantHierarchy::MAX_DEPTH))->toBeTrue()
        ->and(new TenantBounds($deep)->contains('level-0', 'level-' . (TenantHierarchy::MAX_DEPTH + 1)))->toBeFalse()
        ->and(\count([...$deep->ancestors('level-' . (TenantHierarchy::MAX_DEPTH + 5))]))->toBe(TenantHierarchy::MAX_DEPTH);
});

it('gives a subtree root first, each tenant once', function () {
    $doubled = treeOf(static fn (): ?string => null, static fn (): array => ['acme', '', 'acme', 'reseller', 'globex']);

    expect(new TenantHierarchy(new Tree())->subtree('reseller'))->toBe(['reseller', 'acme', 'globex', 'acme-west'])
        ->and(new TenantHierarchy(new Tree())->subtree('acme-west'))->toBe(['acme-west'])
        ->and($doubled->subtree('reseller'))->toBe(['reseller', 'acme', 'globex']);
});

// A part of a subtree would be a silently wrong answer.
it('refuses a subtree larger than a read can carry, rather than reading part of it', function () {
    $huge = treeOf(static fn (): ?string => null, static fn (): array => array_map(
        static fn (int $i): string => "t{$i}",
        range(1, TenantHierarchy::MAX_SUBTREE),
    ));

    expect(fn () => $huge->subtree('root'))->toThrow(\LengthException::class, 'more than the 10000');
});

it('walks to the limits the Orm holds every tree to', function () {
    $orm = new Orm(PdoConnection::sqlite(':memory:', 'tree'), tenants: new TenantHierarchy(new Tree()));

    expect(TenantHierarchy::MAX_DEPTH)->toBe(TenantBounds::MAX_DEPTH)
        ->and(TenantHierarchy::MAX_SUBTREE)->toBe(TenantBounds::MAX_SUBTREE)
        ->and(new TenantHierarchy(new Tree())->hierarchical())->toBeTrue()
        ->and($orm->tenants()?->contains('reseller', 'acme-west'))->toBeTrue()
        ->and($orm->tenants()?->subtree('acme'))->toBe(['acme', 'acme-west']);
});
