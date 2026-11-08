<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Carbon\CarbonImmutable;
use Tinvar\Api\TenantApiToken;
use Tinvar\Api\TenantApiTokenStatus;
use Tinvar\Data\Data;

/**
 * One of the tenant's API tokens as its page lists it (ADR 0193): never its
 * text, which nothing keeps.
 */
final readonly class TenantApiTokenData extends Data
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $id,
        public string $name,
        public array $scopes,
        public TenantApiTokenStatus $status,
        public string $statusLabel,
        public string $createdBy,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $lastUsedAt,
    ) {}

    public static function of(TenantApiToken $token, TenantApiTokenStatus $status): self
    {
        return new self(
            id: $token->id,
            name: $token->name,
            scopes: $token->scopes,
            status: $status,
            statusLabel: $status->describe(),
            createdBy: $token->createdBy,
            createdAt: $token->createdAt,
            expiresAt: $token->expiresAt,
            lastUsedAt: $token->lastUsedAt,
        );
    }
}
