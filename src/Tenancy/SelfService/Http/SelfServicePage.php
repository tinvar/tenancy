<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Http;

use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Page;
use Tinvar\Auth\Gate;
use Tinvar\Http\HttpException;
use Tinvar\Orm\Orm;
use Tinvar\Tenancy\SelfService\TenantAbility;
use Tinvar\Tenancy\SelfService\TenantAdministration;

/**
 * What the tenant administration pages share (ADR 0155): the tenant they
 * run as, which links and forms to draw, and how an action answers.
 *
 * The tenant is the one the request runs as -- read from its host or its
 * session by the application's #[TenantFrom] and middleware, as every other
 * entry point's -- and never one a request posts. With none, the page is a
 * 404: a tenant's settings exist only in a tenant.
 */
abstract class SelfServicePage
{
    public function __construct(
        protected readonly Orm $orm,
        protected readonly Gate $gate,
    ) {}

    /** The tenant the request runs as, or a 404. */
    final protected function tenant(): string
    {
        $tenant = $this->orm->tenant();
        if ($tenant === '') {
            throw new HttpException(404, 'Open this page on your organization\'s own address.', 'not_found');
        }

        return $tenant;
    }

    /**
     * Whether the signed-in user may do $ability here: which forms the page
     * draws. It decides nothing -- the action behind each form asks again.
     */
    final protected function may(TenantAbility $ability): bool
    {
        return $this->gate->allows(new Authorize($ability->value, TenantAdministration::class), []);
    }

    /**
     * The page's own path, as its #[Page] declares it: where a browser sent
     * to another host comes back to. The root, for a path with parameters.
     */
    final protected function path(): string
    {
        $path = $this->page()->path ?? '/';

        return str_contains($path, '{') || !str_starts_with($path, '/') ? '/' : $path;
    }

    /**
     * An action's answer: the page drawn again, by the name the
     * application's #[Page] gave it, with $value for the view to read.
     */
    final protected function again(mixed $value = null): ActionResult
    {
        $name = $this->page()?->name;
        if ($name === null || $name === '') {
            throw new \LogicException(static::class . ' is drawn again after each change by its route name: give its #[Page] a name.');
        }

        return ActionResult::revalidate($name)->withValue($value);
    }

    private function page(): ?Page
    {
        $attribute = new \ReflectionClass(static::class)->getAttributes(Page::class)[0] ?? null;

        return $attribute?->newInstance();
    }
}
