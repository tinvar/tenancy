<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/** Each change the tenant self-service pages make, as TenantActivity is told of it. */
enum TenantChange: string
{
    case Switched = 'tenant.switched';
    case Renamed = 'tenant.renamed';
    case SlugChanged = 'tenant.slug_changed';
    case MemberInvited = 'tenant.member_invited';
    case InviteRevoked = 'tenant.invite_revoked';
    case InviteAccepted = 'tenant.invite_accepted';
    case RoleChanged = 'tenant.role_changed';
    case MemberRemoved = 'tenant.member_removed';
    case DomainAdded = 'tenant.domain_added';
    case DomainVerified = 'tenant.domain_verified';
    case DomainRemoved = 'tenant.domain_removed';
}
