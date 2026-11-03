<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * A slug another tenant holds: TenantProfiles::changeSlug() refuses it, as
 * the store's unique index said. The words are the settings page's.
 */
final class SlugTaken extends \RuntimeException
{
    public function __construct(public readonly string $slug, ?\Throwable $previous = null)
    {
        parent::__construct("Another organization uses '{$slug}'. Choose another.", 0, $previous);
    }
}
