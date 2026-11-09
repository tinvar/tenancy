<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Http;

use Carbon\CarbonImmutable;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Gate;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\ThrottleStore;
use Tinvar\Orm\Orm;
use Tinvar\Tenancy\SelfService\Data\IssuedWebhookSecretData;
use Tinvar\Tenancy\SelfService\Data\TenantWebhookData;
use Tinvar\Tenancy\SelfService\Data\TenantWebhooksPageData;
use Tinvar\Tenancy\SelfService\Data\WebhookDeliveryData;
use Tinvar\Tenancy\SelfService\Requests\CreateWebhookRequest;
use Tinvar\Tenancy\SelfService\Requests\ResendWebhookRequest;
use Tinvar\Tenancy\SelfService\Requests\UpdateWebhookRequest;
use Tinvar\Tenancy\SelfService\Requests\WebhookRequest;
use Tinvar\Tenancy\SelfService\TenantAbility;
use Tinvar\Tenancy\SelfService\TenantActivity;
use Tinvar\Tenancy\SelfService\TenantAdministration;
use Tinvar\Tenancy\SelfService\TenantChange;
use Tinvar\Validation\ValidationException;
use Tinvar\Webhooks\DatabaseWebhookSubscriptions;
use Tinvar\Webhooks\DeliveryAttempt;
use Tinvar\Webhooks\DeliveryLog;
use Tinvar\Webhooks\IssuedWebhookSecret;
use Tinvar\Webhooks\OutboundWebhooks;
use Tinvar\Webhooks\SentWebhooks;
use Tinvar\Webhooks\StoredWebhookSubscription;
use Tinvar\Webhooks\WebhookSubscriptionRefused;

/**
 * A tenant's own webhook subscriptions, ready-made (ADR 0197, as ADR
 * 0161's pages are): the page where a member holding the permission lists
 * the organization's subscriptions -- where each goes, the events it is
 * sent, where it stands and, disabled, why -- makes one, its secret shown
 * once in the answer to the action, changes, pauses, resumes and deletes
 * one, rotates its secret, sends it a test event, and opens its deliveries
 * (`?subscription=<id>`), sending one again as a new delivery.
 *
 *     #[Page('/settings/webhooks', view: 'tenancy/webhooks', name: 'tenancy.webhooks'),
 *       Domain('{tenant}.{root}'), TenantFrom(Host::class), Panel('customer')]
 *     final class OrganizationWebhooks extends TenantWebhooksPage
 *     {
 *         protected function events(): string
 *         {
 *             return OrderEvent::class;
 *         }
 *     }
 *
 * Seeing it is `viewTenantWebhooks`; each change is `manageTenantWebhooks`
 * too, and a resend `resendTenantWebhooks`, each on its final action --
 * `<Class>.create`, `.update`, `.pause`, `.resume`, `.remove`, `.rotate`,
 * `.test`, `.resend` -- whose defaults ask for the `tenant.webhooks`
 * permission, an owner's, or the ones the application's policy names for
 * each of seeing, changing and resending. Tests and resends each send a
 * third party a delivery from the platform's addresses, so an organization
 * may make TESTS_PER_MINUTE and RESENDS_PER_MINUTE of them, counted per
 * organization in the application's ThrottleStore, and a 429 past that. Each
 * change is recorded with TenantActivity, the subscription by its id, its
 * URL's origin and its events: never its secret, nor the URL's path and
 * query, where a receiver may have put a credential of its own.
 *
 * Every read and write is in the tenant the request runs as: the store,
 * the delivery log and the sent events each name it, so an id from another
 * tenant's page finds nothing here.
 */
#[Authorize(TenantAbility::ViewWebhooks->value, TenantAdministration::class)]
abstract class TenantWebhooksPage extends SelfServicePage
{
    /** The deliveries listed for the open subscription. */
    public const int DELIVERIES = 25;

    /** The event a test sends, whatever the subscription chose. */
    public const string TEST_EVENT = 'webhook.test';

    /** Test events one organization may send a minute. */
    public const int TESTS_PER_MINUTE = 10;

    /** Deliveries one organization may send again a minute. */
    public const int RESENDS_PER_MINUTE = 30;

    public function __construct(
        Orm $orm,
        Gate $gate,
        protected readonly DatabaseWebhookSubscriptions $subscriptions,
        protected readonly OutboundWebhooks $webhooks,
        protected readonly DeliveryLog $log,
        protected readonly SentWebhooks $sent,
        protected readonly TenantActivity $activity,
        protected readonly AuthContext $auth,
        protected readonly ThrottleStore $throttles,
    ) {
        parent::__construct($orm, $gate);
    }

    final public function __invoke(Request $request): TenantWebhooksPageData
    {
        $tenant = $this->tenant();
        $now = CarbonImmutable::now('UTC');
        $open = $request->query['subscription'] ?? null;
        $opened = \is_string($open) ? $this->subscriptions->get($open) : null;
        $actions = [];
        foreach (['create', 'update', 'pause', 'resume', 'remove', 'rotate', 'test', 'resend'] as $method) {
            $actions[$method] = $this->action($method);
        }

        return new TenantWebhooksPageData(
            array_map(static fn (StoredWebhookSubscription $s): TenantWebhookData => TenantWebhookData::of($s, $now), $this->subscriptions->all()),
            $this->eventNames(),
            $opened?->id,
            $opened === null ? [] : $this->deliveries($tenant, $opened->id),
            $this->may(TenantAbility::ManageWebhooks),
            $this->may(TenantAbility::ResendWebhooks),
            $actions,
        );
    }

    /** Makes a subscription for the tenant: its secret is in this answer's value and nowhere else, ever. */
    #[Action(transaction: true), Authorize(TenantAbility::ManageWebhooks->value, TenantAdministration::class)]
    final public function create(CreateWebhookRequest $input): ActionResult
    {
        $tenant = $this->tenant();
        $issued = $this->refusing(fn (): IssuedWebhookSecret => $this->subscriptions->create(
            $input->url,
            $this->chosen($input->events),
            $input->description,
            $this->actor()->authIdentifier(),
        ));
        $this->record(TenantChange::WebhookCreated, $tenant, $issued->subscription);

        return $this->again(new IssuedWebhookSecretData(TenantWebhookData::of($issued->subscription, CarbonImmutable::now('UTC')), $issued->secret));
    }

    /** Changes a subscription's URL, events and description. */
    #[Action(transaction: true), Authorize(TenantAbility::ManageWebhooks->value, TenantAdministration::class)]
    final public function update(UpdateWebhookRequest $input): ActionResult
    {
        $tenant = $this->tenant();
        $events = $this->chosen($input->events);
        $before = $this->found($input->subscription);
        $this->refusing(fn (): bool => $this->subscriptions->update($input->subscription, $input->url, $events, $input->description));
        $after = $this->found($input->subscription);
        $this->record(TenantChange::WebhookUpdated, $tenant, $after, [
            'old_origin' => self::origin($before->url),
            'old_events' => implode(' ', $before->events),
        ]);

        return $this->again();
    }

    /** Pauses a subscription: nothing is sent to it, and a delivery due to it ends unsent, until it is resumed. */
    #[Action(transaction: true), Authorize(TenantAbility::ManageWebhooks->value, TenantAdministration::class)]
    final public function pause(WebhookRequest $input): ActionResult
    {
        $tenant = $this->tenant();
        $subscription = $this->found($input->subscription);
        if (!$this->subscriptions->pause($subscription->id)) {
            throw new HttpException(409, 'That subscription is not active.', 'conflict');
        }
        $this->record(TenantChange::WebhookPaused, $tenant, $subscription);

        return $this->again();
    }

    /** Sends to a paused or disabled subscription again, its failures forgotten. */
    #[Action(transaction: true), Authorize(TenantAbility::ManageWebhooks->value, TenantAdministration::class)]
    final public function resume(WebhookRequest $input): ActionResult
    {
        $tenant = $this->tenant();
        $subscription = $this->found($input->subscription);
        if (!$this->subscriptions->resume($subscription->id)) {
            throw new HttpException(409, 'That subscription is active already.', 'conflict');
        }
        $this->record(TenantChange::WebhookResumed, $tenant, $subscription);

        return $this->again();
    }

    #[Action(transaction: true), Authorize(TenantAbility::ManageWebhooks->value, TenantAdministration::class)]
    final public function remove(WebhookRequest $input): ActionResult
    {
        $tenant = $this->tenant();
        $subscription = $this->found($input->subscription);
        $this->subscriptions->delete($subscription->id);
        $this->record(TenantChange::WebhookDeleted, $tenant, $subscription);

        return $this->again();
    }

    /**
     * Gives a subscription a new secret, in this answer's value and nowhere
     * else; the one it replaces signs beside it for the store's overlap.
     */
    #[Action(transaction: true), Authorize(TenantAbility::ManageWebhooks->value, TenantAdministration::class)]
    final public function rotate(WebhookRequest $input): ActionResult
    {
        $tenant = $this->tenant();
        $this->found($input->subscription);
        $issued = $this->refusing(fn (): ?IssuedWebhookSecret => $this->subscriptions->rotate($input->subscription))
            ?? throw new HttpException(404, 'The organization holds no such webhook subscription.', 'not_found');
        $this->record(TenantChange::WebhookSecretRotated, $tenant, $issued->subscription, ['secret_version' => (string) $issued->subscription->secretVersion]);

        return $this->again(new IssuedWebhookSecretData(TenantWebhookData::of($issued->subscription, CarbonImmutable::now('UTC')), $issued->secret));
    }

    /**
     * Sends an active subscription a test event (TEST_EVENT), whatever
     * events it chose: a delivery like any other, in its log, never kept to
     * be sent again.
     */
    #[Action(transaction: true), Authorize(TenantAbility::ManageWebhooks->value, TenantAdministration::class)]
    final public function test(WebhookRequest $input): ActionResult
    {
        $tenant = $this->tenant();
        $subscription = $this->found($input->subscription);
        if (!$subscription->active()) {
            throw new HttpException(409, 'Resume the subscription before sending it a test event.', 'conflict');
        }
        $this->spend('test', $tenant, self::TESTS_PER_MINUTE);
        $delivery = $this->webhooks->send($subscription->id, self::TEST_EVENT, [
            'subscription' => $subscription->id,
            'message' => 'A test event, sent from the webhooks page.',
        ], resendable: false);
        $this->record(TenantChange::WebhookTested, $tenant, $subscription, ['delivery' => $delivery]);

        return $this->again(['delivery' => $delivery]);
    }

    /**
     * Sends one of a subscription's deliveries again, as a new delivery --
     * a new id, so a new nonce -- recorded with the one it repeats and who
     * asked.
     */
    #[Action(transaction: true), Authorize(TenantAbility::ResendWebhooks->value, TenantAdministration::class)]
    final public function resend(ResendWebhookRequest $input): ActionResult
    {
        $tenant = $this->tenant();
        $subscription = $this->found($input->subscription);
        $sent = $this->sent->find($tenant, $input->delivery);
        if ($sent === null || $sent->subscription !== $subscription->id) {
            throw new ValidationException(['delivery' => ['This delivery cannot be sent again: it was not kept, or is not this subscription\'s.']]);
        }
        if (!$subscription->active()) {
            throw new HttpException(409, 'Resume the subscription before sending it a delivery again.', 'conflict');
        }
        $this->spend('resend', $tenant, self::RESENDS_PER_MINUTE);
        $again = $this->webhooks->resend($sent->delivery, $this->actor())
            ?? throw new ValidationException(['delivery' => ['This delivery cannot be sent again: it was not kept.']]);
        $this->record(TenantChange::WebhookResent, $tenant, $subscription, ['delivery' => $sent->delivery, 'resend' => $again]);

        return $this->again(['delivery' => $again]);
    }

    /**
     * The events a subscription may choose: the application's enum of
     * them, each case's value an event name it publishes.
     *
     * @return class-string<\BackedEnum>
     */
    abstract protected function events(): string;

    /**
     * The name a resend's maker is shown by, from their identifier: the
     * identifier itself unless the application looks its accounts up.
     */
    protected function accountName(string $id): string
    {
        return $id;
    }

    /** @return list<string> */
    private function eventNames(): array
    {
        $enum = $this->events();
        if (!enum_exists($enum) || !is_subclass_of($enum, \BackedEnum::class)) {
            throw new \LogicException(static::class . '::events() names ' . $enum . ', which is no backed enum of event names.');
        }

        return array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases());
    }

    /**
     * $events, every one an event the application declares.
     *
     * @param array<array-key, mixed> $events
     * @return list<string>
     */
    private function chosen(array $events): array
    {
        $known = $this->eventNames();
        $chosen = [];
        foreach ($events as $event) {
            if (!\is_string($event) || !\in_array($event, $known, true)) {
                throw new ValidationException(['events' => ['Choose the events to send from those listed.']]);
            }
            $chosen[] = $event;
        }

        return $chosen;
    }

    /**
     * One of the organization's $what this minute, or a 429 once it has
     * spent $perMinute: counted per organization, whoever in it asks.
     */
    private function spend(string $what, string $tenant, int $perMinute): void
    {
        [$hits, $resetsAt] = $this->throttles->increment("tenant-webhooks.{$what}:" . hash('sha256', $tenant), 60);
        if ($hits > $perMinute) {
            throw new HttpException(429, "Too many webhook {$what}s for this organization: try again in a minute.", 'too_many_requests', [
                'retry-after' => (string) max(1, $resetsAt - time()),
            ]);
        }
    }

    /** One of the tenant's subscriptions, or a 404. */
    private function found(string $id): StoredWebhookSubscription
    {
        return $this->subscriptions->get($id)
            ?? throw new HttpException(404, 'The organization holds no such webhook subscription.', 'not_found');
    }

    private function actor(): Authenticatable
    {
        return $this->auth->user() ?? throw new HttpException(403, 'Sign in to change the organization\'s webhooks.', 'forbidden');
    }

    /**
     * The store's work, its refusal the form's error on the field it is about.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function refusing(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (WebhookSubscriptionRefused $e) {
            throw new ValidationException([\in_array($e->field, ['url', 'events', 'description'], true) ? $e->field : 'subscription' => [$e->getMessage()]]);
        }
    }

    /** @param array<string, string> $details */
    private function record(TenantChange $change, string $tenant, StoredWebhookSubscription $subscription, array $details = []): void
    {
        $this->activity->record($change, $tenant, $this->auth->user(), [
            'id' => $subscription->id,
            'origin' => self::origin($subscription->url),
            'events' => implode(' ', $subscription->events),
            ...$details,
        ]);
    }

    /** A URL's scheme, host and port alone: its path and query may carry the receiver's own credential. */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** @return list<WebhookDeliveryData> the subscription's latest deliveries, newest first */
    private function deliveries(string $tenant, string $subscription): array
    {
        // The log, newest first: a delivery's first row here is its latest try.
        $latest = [];
        $tries = [];
        foreach ($this->log->forSubscription($tenant, $subscription, self::DELIVERIES * 6) as $attempt) {
            $latest[$attempt->delivery] ??= $attempt;
            $tries[$attempt->delivery] = max($tries[$attempt->delivery] ?? 0, $attempt->attempt);
        }
        $latest = \array_slice($latest, 0, self::DELIVERIES, true);
        $kept = $this->sent->many($tenant, array_map(strval(...), array_keys($latest)));

        return array_values(array_map(
            function (DeliveryAttempt $attempt) use ($kept, $tries): WebhookDeliveryData {
                $sent = $kept[$attempt->delivery] ?? null;

                return new WebhookDeliveryData(
                    id: $attempt->delivery,
                    event: $attempt->event,
                    outcome: $attempt->outcome,
                    status: $attempt->status,
                    reason: $attempt->reason,
                    attempts: $tries[$attempt->delivery] ?? $attempt->attempt,
                    milliseconds: $attempt->milliseconds,
                    attemptedAt: CarbonImmutable::createFromTimestampUTC($attempt->attemptedAt),
                    resendable: $sent !== null,
                    resendOf: $sent?->resendOf,
                    resentBy: $sent?->resentBy === null ? null : $this->accountName($sent->resentBy),
                );
            },
            $latest,
        ));
    }
}
