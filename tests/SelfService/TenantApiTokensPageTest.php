<?php

declare(strict_types=1);

/**
 * The ready-made page for a tenant's own API tokens (ADR 0193): acme's
 * owner lists the organization's tokens, makes one -- its text in the
 * action's answer alone -- revokes one and ends them all, each change
 * recorded without the token's text; an admin, holding no
 * `tenant.api-tokens`, sees nothing and makes nothing; and a check that
 * no longer allows the member when the epoch is read makes nothing.
 */

namespace Tinvar\Tests\Tenancy\SelfService\ApiTokens;

use Carbon\CarbonImmutable;
use Tinvar\Actions\ActionResult;
use Tinvar\Api\ApiTokenChoice;
use Tinvar\Api\ApiTokenRefused;
use Tinvar\Api\ArrayTenantApiTokens;
use Tinvar\Api\IssuedTenantApiToken;
use Tinvar\Api\TenantApiTokens;
use Tinvar\Api\TenantApiTokenStatus;
use Tinvar\Api\TokenHolderLookup;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Gate;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Auth\TenantTokenActor;
use Tinvar\Http\Request;
use Tinvar\Tenancy\SelfService\Data\IssuedTenantApiTokenData;
use Tinvar\Tenancy\SelfService\Http\TenantApiTokensPage;
use Tinvar\Tenancy\SelfService\Requests\CreateTenantApiTokenRequest;
use Tinvar\Tenancy\SelfService\Requests\TenantApiTokenRequest;
use Tinvar\Tenancy\SelfService\TenantAbility;
use Tinvar\Tenancy\SelfService\TenantAdministration;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdminWorld;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdministrationRules;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\Person;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\RecordedActivity;

require_once __DIR__ . '/Fixtures/self_service.php';

/** The application's page: it makes a token straight through TenantApiTokens, the confirmation its own. */
#[\Tinvar\Attributes\Page('/settings/api-tokens', view: 'tenancy/api-tokens', name: 'tenancy.apiTokens'), \Tinvar\Attributes\Panel('customer')]
final class OrganizationApiTokens extends TenantApiTokensPage
{
    public static bool $stillAllowed = true;

    protected function issue(Request $http, string $name, array $scopes, CarbonImmutable $expiresAt, \Closure $allowed): IssuedTenantApiToken
    {
        return $this->tokens->issue($name, $scopes, $expiresAt, allowed: static fn (): bool => self::$stillAllowed && $allowed());
    }
}

final class Holders implements TokenHolderLookup
{
    public function find(string $id): ?Authenticatable
    {
        return new Person($id, '', '');
    }

    public function epoch(Authenticatable $user): string
    {
        return '1';
    }

    public function authEpoch(Authenticatable $user): string
    {
        return '1';
    }
}

final class Page
{
    public readonly AdminWorld $world;
    public readonly AuthContext $auth;
    public readonly PanelContext $panels;
    public readonly ArrayTenantApiTokens $store;
    public readonly TenantApiTokens $tokens;
    public readonly RecordedActivity $activity;
    public readonly OrganizationApiTokens $page;
    public readonly Gate $gate;

    public function __construct()
    {
        OrganizationApiTokens::$stillAllowed = true;
        $this->world = new AdminWorld();
        $this->auth = new AuthContext();
        $this->panels = new PanelContext();
        $this->store = new ArrayTenantApiTokens();
        $this->activity = new RecordedActivity();
        $orm = $this->world->orm;
        $rules = new AdministrationRules($orm, $this->world->members, $this->panels);
        $this->gate = new PolicyGate(new PolicyRegistry(static fn (string $class): object => $rules)->register(TenantAdministration::class, AdministrationRules::class), $this->auth);
        $this->tokens = new TenantApiTokens($this->store, static fn (string $t): bool => true, new Holders(), $this->auth, $orm, ['customer' => ['notes:read', 'notes:write']], $this->panels);
        $this->page = new OrganizationApiTokens($orm, $this->gate, $this->tokens, $this->activity, $this->auth);
    }

    /**
     * Runs $work as $member's request in acme's customer panel would.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function as(string $member, \Closure $work, string $tenant = 'acme'): mixed
    {
        return $this->auth->actingAs(
            new Person($member, '', ''),
            fn (): mixed => $this->world->orm->actAs($tenant, fn (): mixed => $this->panels->within('customer', $work)),
            '1',
        );
    }

    public function may(string $member, TenantAbility $ability): bool
    {
        return $this->as($member, fn (): bool => $this->gate->allows(new Authorize($ability->value, TenantAdministration::class), []));
    }

    public function create(string $member = 'm-1', string $name = 'Warehouse sync'): ActionResult
    {
        return $this->as($member, fn (): ActionResult => $this->page->create(new CreateTenantApiTokenRequest($name, ['notes:read'], 30), new Request('POST', '/')));
    }
}

it('lists the organization\'s tokens, makes one shown once, revokes one and ends them all, recording each without its text', function () {
    $p = new Page();

    $made = $p->create();
    $issued = $made->value;
    \assert($issued instanceof IssuedTenantApiTokenData);
    $second = $p->create(name: 'Reporting')->value;
    \assert($second instanceof IssuedTenantApiTokenData);
    $listed = $p->as('m-1', fn () => ($p->page)());

    expect($issued->plaintext)->toStartWith('tvt_')
        ->and($issued->token->createdBy)->toBe('m-1')
        ->and($listed->canManage)->toBeTrue()
        ->and($listed->scopes)->toBe(['notes:read', 'notes:write'])
        ->and(array_map(static fn ($t): string => $t->name, $listed->tokens))->toEqualCanonicalizing(['Warehouse sync', 'Reporting'])
        ->and(json_encode($listed))->not->toContain($issued->plaintext)
        ->and($listed->createAction)->toBe('OrganizationApiTokens.create');

    $p->as('m-1', fn () => $p->page->revoke(new TenantApiTokenRequest($issued->token->id)));
    expect($p->tokens->statusOf($p->store->find($issued->token->id)[0]))->toBe(TenantApiTokenStatus::Revoked)
        ->and(fn () => $p->as('m-1', fn () => $p->page->revoke(new TenantApiTokenRequest($issued->token->id))))->toThrow(\Tinvar\Http\HttpException::class);

    $p->as('m-1', fn () => $p->page->endAll());
    expect($p->tokens->authenticate($second->plaintext))->toBeNull()
        ->and($p->tokens->statusOf($p->store->find($second->token->id)[0]))->toBe(TenantApiTokenStatus::Ended)
        ->and($p->activity->changes())->toBe(['tenant.api_token_created', 'tenant.api_token_created', 'tenant.api_token_revoked', 'tenant.api_tokens_ended'])
        ->and(json_encode($p->activity))->not->toContain($issued->plaintext);
});

it('lets the owner, by default, and neither an admin, a member, another tenant\'s owner nor a token see or manage them', function () {
    $p = new Page();
    $token = new TenantTokenActor(str_repeat('a', 32), 'acme', 'customer', ['notes:read'], 'Sync');

    expect($p->may('m-1', TenantAbility::ManageApiTokens))->toBeTrue()
        ->and($p->may('m-1', TenantAbility::ViewApiTokens))->toBeTrue()
        ->and($p->may('m-2', TenantAbility::ManageApiTokens))->toBeFalse()
        ->and($p->may('m-2', TenantAbility::ViewApiTokens))->toBeFalse()
        ->and($p->may('m-3', TenantAbility::ViewApiTokens))->toBeFalse()
        ->and($p->may('m-9', TenantAbility::ViewApiTokens))->toBeFalse()
        ->and($p->auth->actingAs($token, fn (): bool => $p->world->orm->actAs('acme', fn (): bool => $p->panels->within('customer', fn (): bool => $p->gate->allows(new Authorize(TenantAbility::ViewApiTokens->value, TenantAdministration::class), [])))))->toBeFalse();
});

it('makes nothing for a member the check no longer allows once the epoch is read', function () {
    $p = new Page();
    OrganizationApiTokens::$stillAllowed = false;

    try {
        $p->create();
        $this->fail('A token was made for a member no longer allowed.');
    } catch (ApiTokenRefused $e) {
        expect($e->choice)->toBe(ApiTokenChoice::Caller);
    }
    expect($p->store->tokens)->toBe([])
        ->and($p->activity->changes())->toBe([]);
});

it('registers as a page and its actions, by the build rules', function () {
    $actions = new \Tinvar\Actions\ActionRegistry();
    $actions->registerAll([OrganizationApiTokens::class]);
    $routes = new \Tinvar\Routing\RouteRegistry();
    $routes->registerAll([OrganizationApiTokens::class]);

    expect(array_map(static fn ($a): string => $a->name, $actions->all()))->toEqualCanonicalizing([
        'OrganizationApiTokens.create', 'OrganizationApiTokens.revoke', 'OrganizationApiTokens.endAll',
    ]);
});

it('carries #[Authorize] on its final loader and each final action', function () {
    $reflection = new \ReflectionClass(TenantApiTokensPage::class);
    $abilities = static fn (string $method): array => array_map(
        static fn (Authorize $a): string => $a->ability,
        Authorize::declaredOn(TenantApiTokensPage::class, $method),
    );

    expect($abilities('__invoke'))->toBe(['viewTenantApiTokens'])
        ->and($abilities('create'))->toBe(['viewTenantApiTokens', 'manageTenantApiTokens'])
        ->and($abilities('revoke'))->toBe(['viewTenantApiTokens', 'manageTenantApiTokens'])
        ->and($abilities('endAll'))->toBe(['viewTenantApiTokens', 'manageTenantApiTokens'])
        ->and($reflection->getMethod('create')->isFinal())->toBeTrue()
        ->and($reflection->getMethod('revoke')->isFinal())->toBeTrue()
        ->and($reflection->getMethod('endAll')->isFinal())->toBeTrue();
});
