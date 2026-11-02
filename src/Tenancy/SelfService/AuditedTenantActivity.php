<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Audit\AuditLog;
use Tinvar\Auth\Authenticatable;

/**
 * The self-service pages' changes in the audit log (ADR 0156): each an
 * event named by its TenantChange -- `tenant.renamed`, `tenant.domain_added`
 * -- with what changed beside it, in the log of the tenant the request runs
 * as, and who made it as the log reads it for every entry (the user, an
 * impersonator, a job and its starter).
 *
 * Recorded inside the action's transaction, which each page opens around
 * its change, so the entry commits or rolls back with what it describes. A
 * switch is filed in the log of the tenant left, naming the one gone to:
 * the request runs as the first, and a log is never written as another.
 */
final readonly class AuditedTenantActivity implements TenantActivity
{
    public function __construct(private AuditLog $audit) {}

    public function record(TenantChange $change, string $tenant, ?Authenticatable $actor, array $details = []): void
    {
        $this->audit->event($change, null, ['tenant' => $tenant, ...$details]);
    }
}
