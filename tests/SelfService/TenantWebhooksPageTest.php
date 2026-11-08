<?php

declare(strict_types=1);

/**
 * The ready-made page for a tenant's own webhook subscriptions (ADR 0197):
 * acme's owner makes one -- its secret in the action's answer alone --
 * changes, pauses, resumes, rotates, tests and deletes it, each change
 * recorded with neither the secret nor the URL's path; opens one
 * subscription's deliveries and sends one again as a new delivery; an
 * admin, holding no `tenant.webhooks`, sees nothing; globex's owner finds
 * none of acme's; and a disabled subscription is recorded and mailed to
 * the members who hold the permission.
 */

namespace Tinvar\Tests\Tenancy\SelfService\Webhooks;

use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\OutboundPolicy;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Gate;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Mail\Address;
use Tinvar\Mail\ArrayTransport;
use Tinvar\Mail\Email;
use Tinvar\Mail\LinkPolicy;
use Tinvar\Mail\Mailable;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\MailRenderer;
use Tinvar\Mail\RenderedMail;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Outbound\StaticResolver;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Support\Signer;
use Tinvar\Tenancy\SelfService\Data\IssuedWebhookSecretData;
use Tinvar\Tenancy\SelfService\Data\TenantWebhooksPageData;
use Tinvar\Tenancy\SelfService\Http\TenantWebhooksPage;
use Tinvar\Tenancy\SelfService\NotifiedWebhookDisabling;
use Tinvar\Tenancy\SelfService\Requests\CreateWebhookRequest;
use Tinvar\Tenancy\SelfService\Requests\ResendWebhookRequest;
use Tinvar\Tenancy\SelfService\Requests\UpdateWebhookRequest;
use Tinvar\Tenancy\SelfService\Requests\WebhookRequest;
use Tinvar\Tenancy\SelfService\TenantAbility;
use Tinvar\Tenancy\SelfService\TenantAdministration;
use Tinvar\Tenancy\SelfService\TenantAdministrationPolicy;
use Tinvar\Tenancy\SelfService\TenantMember;
use Tinvar\Tenancy\SelfService\WebhookDisabledMails;
use Tinvar\Validation\ValidationException;
use Tinvar\Webhooks\ArrayDeliveryLog;
use Tinvar\Webhooks\ArraySentWebhooks;
use Tinvar\Webhooks\DatabaseWebhookSubscriptions;
use Tinvar\Webhooks\DeliveryAttempt;
use Tinvar\Webhooks\OutboundWebhooks;
use Tinvar\Webhooks\StoredWebhookSubscription;
use Tinvar\Webhooks\WebhookSubscriptionStatus;
use Tinvar\Webhooks\WebhookUrlCheck;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdminWorld;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\AdministrationRules;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\Person;
use Tinvar\Tests\Tenancy\SelfService\Fixtures\RecordedActivity;

require_once __DIR__ . '/Fixtures/self_service.php';

const KEY = 'tenant-webhooks-page-test-key-32-bytes!';

/** The events the application publishes. */
enum OrderEvent: string
{
    case Placed = 'order.placed';
    case Shipped = 'order.shipped';
}

final readonly class DisabledMailData extends \Tinvar\Data\Data
{
    public function __construct(public string $organization) {}
}

/** The application's page. */
#[\Tinvar\Attributes\Page('/settings/webhooks', view: 'tenancy/webhooks', name: 'tenancy.webhooks'), \Tinvar\Attributes\Panel('customer')]
final class OrganizationWebhooks extends TenantWebhooksPage
{
    protected function events(): string
    {
        return OrderEvent::class;
    }
}

/** An application whose policy names a permission of its own for resending. */
final class SupportResends extends TenantAdministrationPolicy
{
    protected function webhookResendPermission(): string
    {
        return 'support.resend';
    }
}

final class Page
{
    public readonly AdminWorld $world;
    public readonly AuthContext $auth;
    public readonly PanelContext $panels;
    public readonly RecordedActivity $activity;
    public readonly DatabaseWebhookSubscriptions $subscriptions;
    public readonly ArrayQueue $queue;
    public readonly ArrayDeliveryLog $log;
    public readonly ArraySentWebhooks $sent;
    public readonly OutboundWebhooks $webhooks;
    public readonly OrganizationWebhooks $page;
    public readonly Gate $gate;

    public function __construct()
    {
        $this->world = new AdminWorld();
        $orm = $this->world->orm;
        $connection = $orm->statements();
        DatabaseWebhookSubscriptions::define(new Schema($connection, Dialect::for($connection)));
        $this->auth = new AuthContext();
        $this->panels = new PanelContext();
        $this->activity = new RecordedActivity();
        $this->queue = new ArrayQueue();
        $this->log = new ArrayDeliveryLog();
        $this->sent = new ArraySentWebhooks();
        $this->subscriptions = new DatabaseWebhookSubscriptions(
            $orm,
            new Signer(KEY, DatabaseWebhookSubscriptions::PURPOSE),
            new WebhookUrlCheck(new OutboundPolicy(), new StaticResolver(['hooks.acme.example' => ['93.184.215.20'], 'intranet.acme.example' => ['10.1.2.3']])),
        );
        $this->webhooks = new OutboundWebhooks(new Dispatcher($this->queue, $orm, new Signer(KEY, 'jobs'), $this->auth, $this->panels), sent: $this->sent, orm: $orm);
        $rules = new AdministrationRules($orm, $this->world->members, $this->panels);
        $this->gate = new PolicyGate(new PolicyRegistry(static fn (string $class): object => $rules)->register(TenantAdministration::class, AdministrationRules::class), $this->auth);
        $this->page = new OrganizationWebhooks($orm, $this->gate, $this->subscriptions, $this->webhooks, $this->log, $this->sent, $this->activity, $this->auth);
    }

    /**
     * Runs $work as $member's request in $tenant's customer panel would.
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

    public function may(string $member, TenantAbility $ability, ?Gate $gate = null): bool
    {
        return $this->as($member, fn (): bool => ($gate ?? $this->gate)->allows(new Authorize($ability->value, TenantAdministration::class), []));
    }

    public function create(string $url = 'https://hooks.acme.example/in?token=receiver-own-credential', array $events = ['order.placed'], string $member = 'm-1'): IssuedWebhookSecretData
    {
        $issued = $this->as($member, fn (): ActionResult => $this->page->create(new CreateWebhookRequest($url, $events, 'Warehouse')))->value;
        \assert($issued instanceof IssuedWebhookSecretData);

        return $issued;
    }

    /** @param array<string, string> $query */
    public function view(string $member = 'm-1', array $query = [], string $tenant = 'acme'): TenantWebhooksPageData
    {
        return $this->as($member, fn (): TenantWebhooksPageData => ($this->page)(new Request('GET', '/settings/webhooks', [], $query)), $tenant);
    }

    public function stored(string $id): ?StoredWebhookSubscription
    {
        return $this->world->orm->actAs('acme', fn (): ?StoredWebhookSubscription => $this->subscriptions->get($id));
    }
}

/** The errors a refused action gave a field. */
function refused(\Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $e) {
        return $e->errors;
    }

    return [];
}

it('makes a subscription shown once, changes, pauses, resumes, rotates, tests and deletes it, recording each without its secret or path', function () {
    $p = new Page();
    $made = $p->create();
    $id = $made->subscription->id;
    $listed = $p->view();

    expect($made->secret)->toStartWith('whsec_')
        ->and($made->subscription->createdBy)->toBe('m-1')
        ->and($listed->canManage)->toBeTrue()
        ->and($listed->canResend)->toBeTrue()
        ->and($listed->events)->toBe(['order.placed', 'order.shipped'])
        ->and($listed->actions['create'])->toBe('OrganizationWebhooks.create')
        ->and($listed->actions['resend'])->toBe('OrganizationWebhooks.resend')
        ->and(array_map(static fn ($s): string => $s->id, $listed->subscriptions))->toBe([$id])
        ->and(json_encode($listed))->not->toContain($made->secret);

    $p->as('m-1', fn () => $p->page->update(new UpdateWebhookRequest($id, 'https://hooks.acme.example/v2', ['order.placed', 'order.shipped'], 'Warehouse v2')));
    expect($p->stored($id)?->url)->toBe('https://hooks.acme.example/v2')
        ->and($p->stored($id)?->events)->toBe(['order.placed', 'order.shipped']);

    $p->as('m-1', fn () => $p->page->pause(new WebhookRequest($id)));
    expect($p->stored($id)?->status)->toBe(WebhookSubscriptionStatus::Paused)
        ->and(fn () => $p->as('m-1', fn () => $p->page->test(new WebhookRequest($id))))->toThrow(HttpException::class)
        ->and(fn () => $p->as('m-1', fn () => $p->page->pause(new WebhookRequest($id))))->toThrow(HttpException::class);
    $p->as('m-1', fn () => $p->page->resume(new WebhookRequest($id)));

    $rotated = $p->as('m-1', fn () => $p->page->rotate(new WebhookRequest($id)))->value;
    \assert($rotated instanceof IssuedWebhookSecretData);
    expect($rotated->secret)->not->toBe($made->secret)
        ->and($rotated->subscription->previousSecretUntil)->not->toBeNull();

    $tested = $p->as('m-1', fn () => $p->page->test(new WebhookRequest($id)))->value;
    expect($p->queue->pushed())->toHaveCount(1)
        ->and($p->queue->pushed()[0]->payload)->toContain(TenantWebhooksPage::TEST_EVENT)->toContain($tested['delivery'])
        ->and($p->sent->find('acme', $tested['delivery']))->toBeNull();

    $p->as('m-1', fn () => $p->page->delete(new WebhookRequest($id)));
    expect($p->stored($id))->toBeNull()
        ->and($p->activity->changes())->toBe([
            'tenant.webhook_created', 'tenant.webhook_updated', 'tenant.webhook_paused', 'tenant.webhook_resumed',
            'tenant.webhook_secret_rotated', 'tenant.webhook_tested', 'tenant.webhook_deleted',
        ])
        ->and($p->activity->records[0]['details'])->toMatchArray(['id' => $id, 'origin' => 'https://hooks.acme.example', 'events' => 'order.placed'])
        ->and(json_encode($p->activity))->not->toContain($made->secret)->not->toContain($rotated->secret)->not->toContain('receiver-own-credential');
});

it('refuses an event the application does not declare, and a URL its webhooks may not go to, making nothing', function () {
    $p = new Page();

    expect(refused(fn () => $p->create(events: ['order.placed', 'user.deleted'])))->toHaveKey('events')
        ->and(refused(fn () => $p->create(url: 'https://intranet.acme.example/in')))->toHaveKey('url')
        ->and(refused(fn () => $p->create(url: 'http://hooks.acme.example/in')))->toHaveKey('url')
        ->and($p->view()->subscriptions)->toBe([])
        ->and($p->activity->changes())->toBe([]);
});

it('finds none of acme\'s subscriptions from globex\'s page, and lets the owner alone, by default, see or change them', function () {
    $p = new Page();
    $id = $p->create()->subscription->id;

    expect($p->view('m-9', tenant: 'globex')->subscriptions)->toBe([])
        ->and($p->view('m-9', ['subscription' => $id], 'globex')->open)->toBeNull()
        ->and(fn () => $p->as('m-9', fn () => $p->page->delete(new WebhookRequest($id)), 'globex'))->toThrow(HttpException::class)
        ->and(fn () => $p->as('m-9', fn () => $p->page->rotate(new WebhookRequest($id)), 'globex'))->toThrow(HttpException::class)
        ->and($p->stored($id))->not->toBeNull()
        ->and($p->may('m-1', TenantAbility::ManageWebhooks))->toBeTrue()
        ->and($p->may('m-1', TenantAbility::ResendWebhooks))->toBeTrue()
        ->and($p->may('m-2', TenantAbility::ViewWebhooks))->toBeFalse()
        ->and($p->may('m-3', TenantAbility::ViewWebhooks))->toBeFalse()
        ->and($p->may('m-9', TenantAbility::ViewWebhooks))->toBeFalse();

    // A policy naming its own resend permission, which no default role holds.
    $rules = new SupportResends($p->world->orm, $p->world->members, $p->panels);
    $gate = new PolicyGate(new PolicyRegistry(static fn (string $class): object => $rules)->register(TenantAdministration::class, SupportResends::class), $p->auth);
    expect($p->may('m-1', TenantAbility::ManageWebhooks, $gate))->toBeTrue()
        ->and($p->may('m-1', TenantAbility::ResendWebhooks, $gate))->toBeFalse();
});

it('opens one subscription\'s deliveries, and sends one again as a new delivery -- never another subscription\'s', function () {
    $p = new Page();
    $one = $p->create()->subscription->id;
    $two = $p->create(url: 'https://hooks.acme.example/two')->subscription->id;
    [$first] = $p->as('m-1', fn () => $p->webhooks->publish(OrderEvent::Placed, ['number' => 'PW-1'], $p->subscriptions));
    // To both subscriptions: they each chose it.
    expect($p->queue->pushed())->toHaveCount(2);
    $p->log->record(new DeliveryAttempt($first, $one, 'order.placed', 'acme', 1, DeliveryAttempt::FAILED, 503, 'status', 40, 1_800_000_000));
    $p->log->record(new DeliveryAttempt($first, $one, 'order.placed', 'acme', 2, DeliveryAttempt::DELIVERED, 200, null, 30, 1_800_000_030));

    $page = $p->view(query: ['subscription' => $one]);
    expect($page->open)->toBe($one)
        ->and(array_map(static fn ($d): array => [$d->id, $d->outcome, $d->attempts, $d->resendable], $page->deliveries))->toBe([[$first, DeliveryAttempt::DELIVERED, 2, true]])
        ->and($p->view(query: ['subscription' => $two])->deliveries)->toBe([]);

    // Named as the other subscription's: refused, nothing queued.
    expect(refused(fn () => $p->as('m-1', fn () => $p->page->resend(new ResendWebhookRequest($two, $first)))))->toHaveKey('delivery')
        ->and($p->queue->pushed())->toHaveCount(2);

    $again = $p->as('m-1', fn () => $p->page->resend(new ResendWebhookRequest($one, $first)))->value['delivery'];
    expect($again)->not->toBe($first)
        ->and($p->sent->find('acme', $again)?->resendOf)->toBe($first)
        ->and($p->sent->find('acme', $again)?->resentBy)->toBe('m-1')
        ->and($p->sent->find('acme', $again)?->subscription)->toBe($one)
        ->and($p->queue->pushed())->toHaveCount(3)
        ->and($p->activity->changes())->toContain('tenant.webhook_resent')
        // globex finds nothing of acme's to resend.
        ->and(fn () => $p->as('m-9', fn () => $p->page->resend(new ResendWebhookRequest($one, $first)), 'globex'))->toThrow(HttpException::class);
});

it('records a disabled subscription, and mails the members who hold the permission', function () {
    $p = new Page();
    $id = $p->create()->subscription->id;
    $transport = new ArrayTransport();
    $renderer = new class implements MailRenderer {
        public function render(string $template, array $props, ?LinkPolicy $links = null): RenderedMail
        {
            return new RenderedMail('<p>Disabled</p>', 'Disabled');
        }
    };
    $mails = new class implements WebhookDisabledMails {
        public function disabled(StoredWebhookSubscription $subscription, TenantMember $to, string $organization): Mailable
        {
            return new readonly class ($to->email, $organization) implements Mailable {
                public function __construct(private string $to, private string $organization) {}

                public function email(): Email
                {
                    return new Email(new Address($this->to), "A webhook of {$this->organization} was disabled", 'webhook-disabled', new DisabledMailData($this->organization));
                }
            };
        }
    };
    $activity = new RecordedActivity();
    $notice = new NotifiedWebhookDisabling($activity, 'customer', $mails, new Mailer($transport, $renderer, new Address('team@example.test')), $p->world->members, profiles: $p->world->tenants);
    $subscriptions = new DatabaseWebhookSubscriptions($p->world->orm, new Signer(KEY, DatabaseWebhookSubscriptions::PURPOSE), notice: $notice, disableAfter: 1);

    $p->world->orm->actAs('acme', fn () => $subscriptions->deliveryFailed($id, 'timeout'));

    expect($activity->records)->toHaveCount(1)
        ->and($activity->records[0])->toMatchArray(['change' => 'tenant.webhook_disabled', 'tenant' => 'acme', 'actor' => null])
        ->and($activity->records[0]['details'])->toBe(['id' => $id, 'reason' => 'consecutive_failures', 'last_failure' => 'timeout'])
        ->and($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]->subject)->toBe('A webhook of Acme was disabled')
        ->and($p->view()->subscriptions[0]->disabledReason)->toBe('Too many deliveries in a row failed.');
});

it('registers as a page and its actions, by the build rules', function () {
    $actions = new \Tinvar\Actions\ActionRegistry();
    $actions->registerAll([OrganizationWebhooks::class]);
    $routes = new \Tinvar\Routing\RouteRegistry();
    $routes->registerAll([OrganizationWebhooks::class]);

    expect(array_map(static fn ($a): string => $a->name, $actions->all()))->toEqualCanonicalizing([
        'OrganizationWebhooks.create', 'OrganizationWebhooks.update', 'OrganizationWebhooks.pause', 'OrganizationWebhooks.resume',
        'OrganizationWebhooks.delete', 'OrganizationWebhooks.rotate', 'OrganizationWebhooks.test', 'OrganizationWebhooks.resend',
    ]);
});

it('carries #[Authorize] on its final loader and each final action', function () {
    $reflection = new \ReflectionClass(TenantWebhooksPage::class);
    $abilities = static fn (string $method): array => array_map(
        static fn (Authorize $a): string => $a->ability,
        Authorize::declaredOn(TenantWebhooksPage::class, $method),
    );

    expect($abilities('__invoke'))->toBe(['viewTenantWebhooks'])
        ->and($abilities('resend'))->toBe(['viewTenantWebhooks', 'resendTenantWebhooks']);
    foreach (['create', 'update', 'pause', 'resume', 'delete', 'rotate', 'test'] as $method) {
        expect($abilities($method))->toBe(['viewTenantWebhooks', 'manageTenantWebhooks'])
            ->and($reflection->getMethod($method)->isFinal())->toBeTrue();
    }
    expect($reflection->getMethod('resend')->isFinal())->toBeTrue()
        ->and($reflection->getMethod('__invoke')->isFinal())->toBeTrue();
});
