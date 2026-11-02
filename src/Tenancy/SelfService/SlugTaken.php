<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/** A slug another tenant already holds: TenantProfiles::changeSlug() refuses it. */
final class SlugTaken extends \RuntimeException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct("Another tenant already uses the slug '{$slug}'. Choose another.");
    }
}
