<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * What the tenant self-service pages' checks are about: the administration
 * of the tenant the request runs as. Named by class in each #[Authorize] --
 * never made, never read from a request -- so the policy decides about the
 * tenant in force, which it reads from the Orm as the page does.
 *
 * The application registers its policy for it, by discovery:
 *
 *     #[Policy(TenantAdministration::class)]
 *     final class TenantAdministrationRules extends TenantAdministrationPolicy {}
 *
 * With none, every self-service page is refused: PolicyGate has no default
 * allow, and a missing policy fails the request.
 */
final class TenantAdministration
{
    private function __construct() {}
}
