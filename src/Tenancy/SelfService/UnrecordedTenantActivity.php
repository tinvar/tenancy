<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Auth\Authenticatable;

/**
 * No activity log: the self-service pages' changes are made and kept
 * nowhere else. What the kit records to until the application gives it a
 * TenantActivity of its own.
 */
final class UnrecordedTenantActivity implements TenantActivity
{
    public function record(TenantChange $change, string $tenant, ?Authenticatable $actor, array $details = []): void {}
}
