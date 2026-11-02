<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * What the tenant self-service pages ask the application's policy for
 * TenantAdministration (ADR 0159): one ability per thing a page shows or
 * changes, each a public method of that policy.
 *
 * The pages carry them as #[Authorize] on their final loaders and actions,
 * so a subclass in the application cannot drop one; what each answers is
 * the policy's -- TenantAdministrationPolicy's defaults, or the
 * application's own.
 */
enum TenantAbility: string
{
    /** Open the tenant switcher, and switch: the switcher checks the membership itself. */
    case SwitchTenant = 'switchTenant';

    /** See the tenant's custom domains and the DNS records each needs. */
    case ViewDomains = 'viewTenantDomains';

    /** Add, check and remove the tenant's custom domains. */
    case ManageDomains = 'manageTenantDomains';

    /** See the tenant's settings: its name, slug, members and open invites. */
    case ViewSettings = 'viewTenantSettings';

    /** Rename the tenant, or change its slug. */
    case ManageSettings = 'manageTenantSettings';

    /** Invite members, revoke invites, change a member's role, remove a member. */
    case ManageMembers = 'manageTenantMembers';

    /**
     * Open an invite's page and accept it: the emailed link is the
     * credential, and Invitations holds it to its own rules.
     */
    case AcceptInvite = 'acceptTenantInvite';
}
