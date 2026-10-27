<?php

declare(strict_types=1);

/**
 * TenantHierarchy, over an application's TenantTree, held to the tenant
 * tree conformance kit tinvar/testing ships (ADR 0056): what any
 * application's tree is to run too, built over the tree it writes.
 */

namespace Tinvar\Tests\Tenancy\TenantTreeConformance;

use Tinvar\Orm\TenantSubtrees;
use Tinvar\Tenancy\Tenancy;
use Tinvar\Tenancy\TenantTree;
use Tinvar\Testing\TenantTreeConformance;

/** A tree kept as parent links: parentOf() reads them, descendantsOf() walks them down. */
final readonly class ParentLinks implements TenantTree
{
    /** @param array<string, string> $parents child => parent */
    public function __construct(private array $parents) {}

    public function parentOf(string $tenant): ?string
    {
        return $this->parents[$tenant] ?? null;
    }

    public function descendantsOf(string $tenant): array
    {
        $found = [];
        for ($queue = [$tenant]; $queue !== [];) {
            $node = array_shift($queue);
            foreach ($this->parents as $child => $parent) {
                $child = (string) $child;
                if ($parent === $node && $child !== $tenant && !\in_array($child, $found, true)) {
                    $found[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $found;
    }
}

it('is a tenant tree, as the Orm takes one', function (\Closure $case) {
    $case(static fn (array $parents): TenantSubtrees => Tenancy::tree(new ParentLinks($parents)));
})->with(TenantTreeConformance::cases());
