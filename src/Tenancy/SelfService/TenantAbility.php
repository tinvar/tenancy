<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/**
 * What the tenant self-service pages ask the application's policy for
 * TenantAdministration (ADR 0161): one ability per thing a page shows or
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

    /** See the tenant's API tokens: their names, scopes, makers and last use (ADR 0193). */
    case ViewApiTokens = 'viewTenantApiTokens';

    /** Make, revoke, and end every one of, the tenant's API tokens (ADR 0193). */
    case ManageApiTokens = 'manageTenantApiTokens';

    /** See the tenant's webhook subscriptions and each one's deliveries (ADR 0197). */
    case ViewWebhooks = 'viewTenantWebhooks';

    /** Make, change, pause, resume, delete and test the tenant's webhook subscriptions, and rotate their secrets (ADR 0197). */
    case ManageWebhooks = 'manageTenantWebhooks';

    /** Send one of a subscription's deliveries again, as a new delivery (ADR 0197). */
    case ResendWebhooks = 'resendTenantWebhooks';

    /**
     * Open an invite's page and accept it: the emailed link is the
     * credential, and Invitations holds it to its own rules.
     */
    case AcceptInvite = 'acceptTenantInvite';
}
