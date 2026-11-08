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
    case DomainFailing = 'tenant.domain_failing';
    case DomainRestored = 'tenant.domain_restored';
    case DomainLapsed = 'tenant.domain_lapsed';
    case DomainReleased = 'tenant.domain_released';
    case ApiTokenCreated = 'tenant.api_token_created';
    case ApiTokenRevoked = 'tenant.api_token_revoked';
    case ApiTokensEnded = 'tenant.api_tokens_ended';
    case WebhookCreated = 'tenant.webhook_created';
    case WebhookUpdated = 'tenant.webhook_updated';
    case WebhookPaused = 'tenant.webhook_paused';
    case WebhookResumed = 'tenant.webhook_resumed';
    case WebhookDeleted = 'tenant.webhook_deleted';
    case WebhookSecretRotated = 'tenant.webhook_secret_rotated';
    case WebhookTested = 'tenant.webhook_tested';
    case WebhookResent = 'tenant.webhook_resent';
    case WebhookDisabled = 'tenant.webhook_disabled';
}
