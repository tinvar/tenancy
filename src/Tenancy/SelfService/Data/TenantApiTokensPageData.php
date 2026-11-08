<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;

/**
 * The tenant's API tokens page (ADR 0193): its tokens, the scopes a new one
 * may hold, whether the member may make, revoke and end them -- which forms
 * to draw, never what the actions allow -- and the actions the forms post
 * to, named as the application's class is.
 */
final readonly class TenantApiTokensPageData extends Data
{
    /**
     * @param list<TenantApiTokenData> $tokens
     * @param list<string> $scopes
     */
    public function __construct(
        public array $tokens,
        public array $scopes,
        public bool $canManage,
        public string $createAction,
        public string $revokeAction,
        public string $endAllAction,
    ) {}
}
