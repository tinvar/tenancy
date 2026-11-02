<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Http;

use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\Request;
use Tinvar\Tenancy\SelfService\Data\TenantChoiceData;
use Tinvar\Tenancy\SelfService\Data\TenantSwitchPageData;
use Tinvar\Tenancy\SelfService\Requests\SwitchTenantRequest;
use Tinvar\Tenancy\SelfService\TenantAbility;
use Tinvar\Tenancy\SelfService\TenantAdministration;
use Tinvar\Tenancy\SelfService\TenantSwitchboard;
use Tinvar\Tenancy\SelfService\TenantSwitchVia;

/**
 * The tenant switcher, ready-made (ADR 0155): a page listing the tenants
 * the signed-in user belongs to, and the action that switches to one.
 *
 * The application declares it, and discovery registers it:
 *
 *     #[Page('/organizations', view: 'tenancy/switch', name: 'tenancy.switch'), Panel('customer')]
 *     final class SwitchOrganization extends TenantSwitchPage {}
 *
 * Its loader and action are final, and carry their checks: a subclass
 * changes where the page is, its view, its panel, its host and its
 * middleware, and the hooks below -- never what it checks. The action is
 * `<Class>.switchTo`.
 *
 * A switch on this host answers with a redirect to landing(), under the new
 * session the application's TenantSessions started: every token follows
 * from the session, so the page after it is a new binding and the client
 * purges what it held. One to a tenant on a host of its own answers with
 * location() to the handoff's start on that host -- an origin the
 * application's Domains lets actions send browsers to (`redirectable:`, ADR
 * 0149), or the redirect is refused.
 */
#[Authorize(TenantAbility::SwitchTenant->value, TenantAdministration::class)]
abstract class TenantSwitchPage
{
    public function __construct(
        protected readonly TenantSwitchboard $switchboard,
        protected readonly PanelContext $panels,
    ) {}

    final public function __invoke(Request $request): TenantSwitchPageData
    {
        $choices = $this->switchboard->choices($request, $this->panel());
        $current = '';
        foreach ($choices as $choice) {
            if ($choice->via === TenantSwitchVia::Current) {
                $current = $choice->tenant;
            }
        }

        return new TenantSwitchPageData(array_map(TenantChoiceData::of(...), $choices), $current);
    }

    #[Action, Authorize(TenantAbility::SwitchTenant->value, TenantAdministration::class)]
    final public function switchTo(SwitchTenantRequest $input, Request $request): ActionResult
    {
        $destination = $this->switchboard->switch($request, $input->tenant, $this->panel(), $this->landing($input->tenant));

        return $destination->offOrigin() ? ActionResult::location($destination->location) : ActionResult::redirect($destination->location);
    }

    /**
     * The panel whose memberships are listed and switched between: the one
     * the page is declared in.
     */
    protected function panel(): string
    {
        return $this->panels->currentOrNull() ?? throw new \LogicException(
            static::class . ' lists the tenants of a panel: declare #[Panel(...)] on it, or override panel().',
        );
    }

    /** The root-relative path a tenant opens on once switched to. */
    protected function landing(string $tenant): string
    {
        return '/';
    }
}
