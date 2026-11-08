<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Http;

use Carbon\CarbonImmutable;
use Tinvar\Actions\ActionResult;
use Tinvar\Api\IssuedTenantApiToken;
use Tinvar\Api\TenantApiTokens;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Gate;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Orm\Orm;
use Tinvar\Tenancy\SelfService\Data\IssuedTenantApiTokenData;
use Tinvar\Tenancy\SelfService\Data\TenantApiTokenData;
use Tinvar\Tenancy\SelfService\Data\TenantApiTokensPageData;
use Tinvar\Tenancy\SelfService\Requests\CreateTenantApiTokenRequest;
use Tinvar\Tenancy\SelfService\Requests\TenantApiTokenRequest;
use Tinvar\Tenancy\SelfService\TenantAbility;
use Tinvar\Tenancy\SelfService\TenantActivity;
use Tinvar\Tenancy\SelfService\TenantAdministration;
use Tinvar\Tenancy\SelfService\TenantChange;

/**
 * A tenant's own API tokens, ready-made (ADR 0193, as ADR 0161's pages
 * are): the page where a member holding the permission lists the
 * organization's tokens -- who made each, its scopes, when it was last used
 * and where it stands -- makes one, its text shown once in the answer to
 * the action, revokes one, or ends them all.
 *
 *     #[Page('/settings/api-tokens', view: 'tenancy/api-tokens', name: 'tenancy.apiTokens'),
 *       Domain('{tenant}.{root}'), TenantFrom(Host::class), Panel('customer')]
 *     final class OrganizationApiTokens extends TenantApiTokensPage
 *     {
 *         public function __construct(Orm $orm, Gate $gate, TenantApiTokens $tokens, TenantActivity $activity,
 *             AuthContext $auth, private readonly Accounts $accounts, private readonly CurrentUser $user) {
 *             parent::__construct($orm, $gate, $tokens, $activity, $auth);
 *         }
 *
 *         protected function issue(Request $http, string $name, array $scopes, CarbonImmutable $expiresAt, \Closure $allowed): IssuedTenantApiToken
 *         {
 *             return $this->accounts->issueTenantApiToken($http, $this->user->get(), $this->tokens, $name, $scopes, $expiresAt, $allowed);
 *         }
 *     }
 *
 * Seeing it is `viewTenantApiTokens`; making, revoking and ending are
 * `manageTenantApiTokens` too, each on its final action -- `<Class>.create`,
 * `<Class>.revoke` and `<Class>.endAll` -- whose defaults ask for the
 * `tenant.api-tokens` permission, an owner's. Each change is recorded with
 * TenantActivity, the token by its id and name and never its text.
 *
 * How a token is made is the application's to say (issue()), since a token
 * outlives the session that made it and every sign-in: a session alone --
 * a stolen cookie -- must not leave behind a credential that survives the
 * member signing out everywhere. On the accounts kit,
 * Accounts::issueTenantApiToken() asks for a confirmation in the last five
 * minutes and holds the token to the session's credential epoch. Whatever
 * it does, it hands TenantApiTokens::issue() the `$allowed` check, which
 * asks the policy again once the tenant's token epoch is read.
 */
#[Authorize(TenantAbility::ViewApiTokens->value, TenantAdministration::class)]
abstract class TenantApiTokensPage extends SelfServicePage
{
    public function __construct(
        Orm $orm,
        Gate $gate,
        protected readonly TenantApiTokens $tokens,
        protected readonly TenantActivity $activity,
        protected readonly AuthContext $auth,
    ) {
        parent::__construct($orm, $gate);
    }

    final public function __invoke(): TenantApiTokensPageData
    {
        $this->tenant();

        return new TenantApiTokensPageData(
            array_map(static fn (array $listed): TenantApiTokenData => TenantApiTokenData::of($listed[0], $listed[1]), $this->tokens->listed()),
            $this->tokens->grantable(),
            $this->may(TenantAbility::ManageApiTokens),
            $this->action('create'),
            $this->action('revoke'),
            $this->action('endAll'),
        );
    }

    /**
     * Makes a token for the tenant: its text is in this answer's value, an
     * IssuedTenantApiTokenData, and nowhere else, ever.
     */
    #[Action(transaction: true), Authorize(TenantAbility::ManageApiTokens->value, TenantAdministration::class)]
    final public function create(CreateTenantApiTokenRequest $input, Request $http): ActionResult
    {
        $tenant = $this->tenant();
        $issued = $this->issue(
            $http,
            $input->name,
            $input->scopes,
            CarbonImmutable::now('UTC')->addDays($input->days),
            fn (): bool => $this->may(TenantAbility::ManageApiTokens),
        );
        $this->activity->record(TenantChange::ApiTokenCreated, $tenant, $this->auth->user(), [
            'id' => $issued->token->id,
            'name' => $issued->token->name,
            'scopes' => implode(' ', $issued->token->scopes),
        ]);

        return $this->again(new IssuedTenantApiTokenData(
            TenantApiTokenData::of($issued->token, $this->tokens->statusOf($issued->token)),
            $issued->plaintext,
        ));
    }

    /** Revokes one of the tenant's tokens: refused from the next call on, on every server. */
    #[Action(transaction: true), Authorize(TenantAbility::ManageApiTokens->value, TenantAdministration::class)]
    final public function revoke(TenantApiTokenRequest $input): ActionResult
    {
        $tenant = $this->tenant();
        if (!$this->tokens->revoke($input->token)) {
            throw new HttpException(404, 'The organization holds no such token, or it was revoked already.', 'not_found');
        }
        $this->activity->record(TenantChange::ApiTokenRevoked, $tenant, $this->auth->user(), ['id' => $input->token]);

        return $this->again();
    }

    /**
     * Ends every token the tenant holds at once: for a token feared leaked
     * when nobody knows which, or an owner who left.
     */
    #[Action(transaction: true), Authorize(TenantAbility::ManageApiTokens->value, TenantAdministration::class)]
    final public function endAll(): ActionResult
    {
        $tenant = $this->tenant();
        $this->tokens->endAll();
        $this->activity->record(TenantChange::ApiTokensEnded, $tenant, $this->auth->user());

        return $this->again();
    }

    /**
     * Makes the token, as the application confirms its members: on the
     * accounts kit, Accounts::issueTenantApiToken(). Hand $allowed on to
     * TenantApiTokens::issue().
     *
     * @param list<string> $scopes
     * @param \Closure(): bool $allowed the page's own check, asked again once the epoch is read
     */
    abstract protected function issue(Request $http, string $name, array $scopes, CarbonImmutable $expiresAt, \Closure $allowed): IssuedTenantApiToken;
}
