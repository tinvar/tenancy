<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Carbon\CarbonImmutable;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Events\Events;
use Tinvar\Identity\CustomDomain;
use Tinvar\Identity\CustomDomains;
use Tinvar\Identity\DomainPointing;
use Tinvar\Identity\DomainVerifier;
use Tinvar\Identity\Realms;
use Tinvar\Identity\RememberedConsents;
use Tinvar\Orm\Orm;

/**
 * A tenant's custom domain proved again, on a schedule, by the record that
 * first proved it (ADR 0198).
 *
 * A TXT record at `_tinvar-challenge.<host>` carrying the claim's token puts
 * a domain in service (ADR 0161). Before this, nothing asked for it again:
 * the framework's re-verification read where a domain points, and a name
 * that lapsed and was bought by someone else, still pointing here, went on
 * routing to the tenant that once proved it. Now every domain in service is
 * checked against both, through the same DomainOwnership and DomainVerifier
 * the first proof uses:
 *
 *     verified --record missing--> failing --past the grace--> deactivated
 *        ^                            |                      (lapsed: nobody's)
 *        +------record found----------+                            |
 *        +----------------proved again, before release-------------+
 *                                       released after $release --> pending
 *
 * - **Missing counts; unreadable does not.** A check whose TXT answer lacks
 *   the token -- no such name, no record, another token -- starts a run of
 *   misses (`unproved_since`) or extends one. A lookup that failed or timed
 *   out (OwnershipProof::Unreadable) says nothing of who owns the name: it
 *   neither starts a run nor ends one, and is not a step toward the lapse.
 * - **Failing is still served.** A tenant that deleted its record by
 *   mistake, or is moving DNS providers, keeps its site for $proofGrace,
 *   told by its domains page and by whatever the application does with
 *   TenantDomainChanged. The first check after the grace -- to the second
 *   -- lapses it.
 * - **Lapsed is nobody's, at once.** A lapse clears the host the domain
 *   held in the same statement, so the next lookup -- tenantForHost(),
 *   realm(), customDomain(), primaryFor() -- answers as for a host never
 *   seen, and the remembered consents for its origin are forgotten.
 * - **Where it points** is read as CustomDomainVerification reads it, by
 *   the same rules and grace: elsewhere lapses it at once, unreadable past
 *   $grace lapses it.
 * - **A lapsed claim is released** $release seconds after it lapsed: it is
 *   pending again with a fresh token, so the record that proved it before
 *   proves nothing, and the schedule stops checking it. Until then the
 *   schedule checks it daily, and a proof puts it back.
 * - **Another tenant proves it.** The host is released the moment a domain
 *   lapses, and anyone may claim and prove it with a token of their own.
 *   Before that, while the holder is failing because its record is gone,
 *   another tenant may claim it, and a proof by that tenant, checked
 *   against a DNS answer that lacks the holder's token, lapses the holder
 *   at once: the grace protects a tenant from its own mistakes, never from
 *   the name's proven new owner.
 *
 * Every change of state is recorded (TenantActivity, the tenant's audit
 * log) and dispatched as TenantDomainChanged, as the domain's tenant,
 * inside the transaction that made it. A change made on the schedule, or
 * to one tenant's domain by another's proof, is made by nobody.
 *
 * It is also the CustomDomains the framework's CustomDomainVerification is
 * given -- Caddy's ask, and VerifyCustomDomains where an application still
 * lists it -- so a lapse there is recorded and announced the same way.
 */
final readonly class TenantDomainReverification implements CustomDomains
{
    /** Seconds a missing ownership record keeps a domain in service: seven days. */
    public const int DEFAULT_PROOF_GRACE = 604_800;

    /** Seconds between two scheduled checks of a domain: a day. */
    public const int DEFAULT_INTERVAL = 86_400;

    /** Seconds a lapsed claim waits to be proved again before it is released: thirty days. */
    public const int DEFAULT_RELEASE = 2_592_000;

    /** Seconds between two checks of a failing domain, at most: it is restored, or lapses, close to when it should. */
    public const int FAILING_INTERVAL = 3_600;

    /** Seconds a run books a domain's next check out while checking it: one whose check throws is tried again then. */
    public const int LEASE = 600;

    /** Seconds one scheduled run checks for, at most, so runs five minutes apart do not pile up. */
    public const int BUDGET = 240;

    /** How many domains are read at a time. */
    public const int BATCH = 100;

    /**
     * @param int $grace seconds a domain whose DNS cannot be read stays in service (the
     *        pointing grace, CustomDomainsConfig's `GRACE`)
     * @param int $proofGrace seconds a domain whose ownership record is missing stays in service
     * @param int $interval seconds between scheduled checks; each is booked up to an eighth of it
     *        early, by the domain, so checks made together drift apart
     * @param int $release seconds a lapsed claim waits before it is released
     * @param AuthContext|null $auth the application's, to make a change by nobody where a
     *        request's user did not make it: another tenant's domain lapsed by a proof
     */
    public function __construct(
        private Orm $orm,
        private TenantDomains $domains,
        private DomainVerifier $verifier,
        private DomainOwnership $ownership,
        private int $grace,
        private int $proofGrace = self::DEFAULT_PROOF_GRACE,
        private int $interval = self::DEFAULT_INTERVAL,
        private int $release = self::DEFAULT_RELEASE,
        private TenantActivity $activity = new UnrecordedTenantActivity(),
        private ?Events $events = null,
        private ?RememberedConsents $remembered = null,
        private ?Realms $realms = null,
        private ?AuthContext $auth = null,
    ) {
        if ($grace < 0 || $proofGrace < 0) {
            throw new \InvalidArgumentException('A grace period is zero seconds or more.');
        }
        if ($interval < 60 || $release < 0) {
            throw new \InvalidArgumentException('Domains are checked a minute apart at least, and a lapsed claim is released after zero seconds or more.');
        }
    }

    public function now(): CarbonImmutable
    {
        return $this->domains->now();
    }

    public function proofGrace(): int
    {
        return $this->proofGrace;
    }

    /**
     * The last moment a failing domain is served if nothing changes: the
     * first check after it lapses it. Null for one that is not failing.
     */
    public function lapsesAt(TenantDomain $domain): ?CarbonImmutable
    {
        if ($domain->status !== TenantDomainStatus::Failing) {
            return null;
        }
        $at = [];
        if ($domain->failingSince !== null) {
            $at[] = $domain->failingSince->addSeconds($this->grace);
        }
        if ($domain->unprovedSince !== null) {
            $at[] = $domain->unprovedSince->addSeconds($this->proofGrace);
        }

        return $at === [] ? null : min($at);
    }

    /**
     * The moment after which a lapsed claim is released, if it is not proved
     * again by then. Null for one that is not out of service.
     */
    public function releasesAt(TenantDomain $domain): ?CarbonImmutable
    {
        return $domain->status === TenantDomainStatus::Deactivated && $domain->deactivatedAt !== null
            ? $domain->deactivatedAt->addSeconds($this->release)
            : null;
    }

    /**
     * One domain checked now, and the outcome recorded: one in service
     * against its ownership record and its DNS, one pending or out of
     * service proved as it was first proved.
     */
    public function check(TenantDomain $domain, ?Authenticatable $actor = null): TenantDomain
    {
        if ($domain->status->active()) {
            $this->recheck($domain);
        } else {
            $this->prove($domain, $actor);
        }

        return $this->domains->ofTenant($domain->tenant, $domain->id) ?? $domain;
    }

    /**
     * The scheduled run (ReverifyTenantDomains): the lapsed claims due
     * release released, then every domain due a check checked, each claimed
     * first so two runs at once do not check one twice, until none is due
     * or $budget seconds are spent. A domain whose check throws is left for
     * a later run, and the rest go on.
     *
     * @return array{checked: array<string, TenantDomainStatus>, released: list<string>, failures: array<string, \Throwable>}
     */
    public function run(int $budget = self::BUDGET): array
    {
        $deadline = hrtime(true) / 1e9 + $budget;
        $now = $this->now();
        $checked = [];
        $released = [];
        $failures = [];

        $seen = [];
        while (true) {
            $fresh = array_filter(
                $this->domains->releasable($now->subSeconds($this->release), self::BATCH + \count($seen)),
                static fn (TenantDomain $d): bool => !isset($seen[$d->id]),
            );
            if ($fresh === []) {
                break;
            }
            foreach ($fresh as $domain) {
                $seen[$domain->id] = true;
                try {
                    if ($this->releaseClaim($domain, $now)) {
                        $released[] = $domain->host;
                    }
                } catch (\Throwable $e) {
                    $failures[$domain->host] = $e;
                }
            }
        }

        $seen = [];
        while (hrtime(true) / 1e9 < $deadline) {
            $fresh = array_filter(
                $this->domains->dueForCheck($now, self::BATCH + \count($seen)),
                static fn (TenantDomain $d): bool => !isset($seen[$d->id]),
            );
            if ($fresh === []) {
                break;
            }
            foreach ($fresh as $domain) {
                if (hrtime(true) / 1e9 >= $deadline) {
                    break 2;
                }
                $seen[$domain->id] = true;
                try {
                    if (!$this->domains->claimCheck($domain, $now, $now->addSeconds(self::LEASE))) {
                        continue;
                    }
                    $checked[$domain->host] = $this->check($domain)->status;
                } catch (\Throwable $e) {
                    $failures[$domain->host] = $e;
                }
            }
        }

        return ['checked' => $checked, 'released' => $released, 'failures' => $failures];
    }

    /**
     * Whether another tenant than $tenant serves $host: by the table, or
     * by a custom-domain realm the application keeps beside it.
     */
    public function heldElsewhere(string $tenant, string $host): bool
    {
        $realm = $this->realms?->forHost($host);
        $holder = $this->domains->tenantForHost($host) ?? ($realm !== null && $realm->isCustom() ? $realm->tenant : null);

        return $holder !== null && $holder !== $tenant;
    }

    /**
     * Whether $tenant may claim $host though another tenant holds it: the
     * holder is in the table and failing for want of its ownership record,
     * so a proof by $tenant may take it over.
     */
    public function contestable(string $tenant, string $host): bool
    {
        $holder = $this->domains->holding($host);

        return $holder !== null && $holder->tenant !== $tenant && $holder->unprovedSince !== null;
    }

    // CustomDomains: the framework's checks of where a domain points, recorded as ours are.

    public function due(\DateTimeImmutable $since, int $limit): array
    {
        return $this->domains->due($since, $limit);
    }

    public function find(string $host): ?CustomDomain
    {
        return $this->domains->find($host);
    }

    public function passed(string $host, \DateTimeImmutable $at): void
    {
        $this->observed($host, fn () => $this->domains->passed($host, $at));
    }

    public function failed(string $host, \DateTimeImmutable $at, \DateTimeImmutable $failingSince): void
    {
        $this->observed($host, fn () => $this->domains->failed($host, $at, $failingSince));
    }

    public function deactivate(string $host, string $reason, \DateTimeImmutable $at): void
    {
        $this->observed($host, fn () => $this->domains->deactivate($host, $reason, $at), forget: true);
    }

    /** One domain in service, checked against its record and its DNS. */
    private function recheck(TenantDomain $domain): void
    {
        $now = $this->now();
        $proof = $this->ownership->check($domain->host, $domain->token);
        $pointing = $this->verifier->check($domain->host);

        $failingSince = $domain->failingSince;
        if ($pointing->pointing === DomainPointing::Elsewhere) {
            $this->lapse($domain, $now, DomainLapse::Pointing, "Its DNS no longer points at the platform: {$pointing->reason}.");

            return;
        }
        if ($pointing->pointing === DomainPointing::Unknown) {
            $failingSince ??= $now;
            if ($now->getTimestamp() - $failingSince->getTimestamp() > $this->grace) {
                $this->lapse($domain, $now, DomainLapse::Pointing, 'Its DNS could not be read at any check since ' . $failingSince->toAtomString() . ": {$pointing->reason}.");

                return;
            }
        } else {
            $failingSince = null;
        }

        $unprovedSince = match ($proof->proof) {
            OwnershipProof::Proved => null,
            OwnershipProof::Missing => $domain->unprovedSince ?? $now,
            // A lookup that failed is no answer: the run of misses, if any, stands as it was.
            OwnershipProof::Unreadable => $domain->unprovedSince,
        };
        if ($proof->proof === OwnershipProof::Missing && $now->getTimestamp() - $unprovedSince->getTimestamp() > $this->proofGrace) {
            $this->lapse($domain, $now, DomainLapse::Ownership, 'Its ownership record has been missing since ' . $unprovedSince->toAtomString() . ": {$proof->reason}");

            return;
        }

        $status = $failingSince !== null || $unprovedSince !== null ? TenantDomainStatus::Failing : TenantDomainStatus::Verified;
        $reason = match (true) {
            $unprovedSince !== null => ($proof->proof === OwnershipProof::Missing ? $proof->reason : 'Its ownership record was missing, and could not be read at the last check.')
                . ' It is still served until ' . $unprovedSince->addSeconds($this->proofGrace)->toAtomString() . '; publish the record again before then.',
            $failingSince !== null => 'Its DNS could not be read at the last check. It stays in service for now; check its records.',
            $proof->proof === OwnershipProof::Unreadable => 'It points at the platform. Its ownership record could not be read at this check, which does not count against it.',
            default => 'It is yours, and it points at the platform.',
        };

        $this->within($domain->tenant, function () use ($domain, $now, $status, $failingSince, $unprovedSince, $reason): void {
            $changed = $this->domains->rechecked($domain, $now, $this->next($domain, $now, $status, $failingSince, $unprovedSince), $status, $failingSince, $unprovedSince, $reason);
            if ($changed && $status !== $domain->status) {
                $this->announce($domain, $status, $status === TenantDomainStatus::Failing ? TenantChange::DomainFailing : TenantChange::DomainRestored, $reason, null, $now, null);
            }
        });
    }

    /** One domain pending or out of service, proved as it was first proved, and put in service if it is. */
    private function prove(TenantDomain $domain, ?Authenticatable $actor): void
    {
        $now = $this->now();
        $proof = $this->ownership->check($domain->host, $domain->token);
        $missing = $proof->proof === OwnershipProof::Proved ? null : $proof->reason;
        $pointing = $this->verifier->check($domain->host);
        if ($missing === null && $pointing->pointing !== DomainPointing::Platform) {
            $missing = "It does not point at the platform yet: {$pointing->reason}.";
        }
        // A lapsed claim is still the schedule's to check, until it is released.
        $next = $domain->status === TenantDomainStatus::Deactivated ? $this->next($domain, $now, TenantDomainStatus::Verified) : null;

        if ($missing !== null) {
            $this->domains->unproved($domain->tenant, $domain->id, $now, $missing, $next);

            return;
        }

        $taken = 'Another organization uses this domain. If it is yours, contact support.';
        if ($this->heldElsewhere($domain->tenant, $domain->host) && !$this->contest($domain, $now)) {
            $this->domains->unproved($domain->tenant, $domain->id, $now, $taken, $next);

            return;
        }

        $reason = 'It is yours, and it points at the platform.';
        $activated = $this->within($domain->tenant, function () use ($domain, $now, $reason, $actor): bool {
            if (!$this->domains->activate($domain->tenant, $domain->id, $now, $reason, $this->next($domain, $now, TenantDomainStatus::Verified))) {
                return false;
            }
            $change = $domain->status === TenantDomainStatus::Pending ? TenantChange::DomainVerified : TenantChange::DomainRestored;
            $this->announce($domain, TenantDomainStatus::Verified, $change, $reason, null, $now, $actor);

            return true;
        });
        if (!$activated) {
            $this->domains->unproved($domain->tenant, $domain->id, $now, $taken, $next);
        }
    }

    /**
     * $claimant has proved a host another tenant holds: the holder lapses,
     * by nobody, when DNS answers without its token now. False -- the host
     * stays its holder's -- when its record is there, cannot be read, or
     * the host is held by a realm of the application's beside the table.
     */
    private function contest(TenantDomain $claimant, CarbonImmutable $now): bool
    {
        $holder = $this->domains->holding($claimant->host);
        if ($holder === null || $holder->tenant === $claimant->tenant || $holder->unprovedSince === null) {
            return false;
        }
        if ($this->ownership->check($holder->host, $holder->token)->proof !== OwnershipProof::Missing) {
            return false;
        }

        return $this->lapse($holder, $now, DomainLapse::Claimed, 'Another organization proved it owns this domain, and your ownership record is no longer there.');
    }

    /** Takes $domain out of service, records and announces it, and forgets its remembered consents. */
    private function lapse(TenantDomain $domain, CarbonImmutable $now, DomainLapse $lapse, string $reason): bool
    {
        return $this->asNobody(fn (): bool => $this->within($domain->tenant, function () use ($domain, $now, $lapse, $reason): bool {
            if (!$this->domains->lapse($domain, $now, $this->next($domain, $now, TenantDomainStatus::Verified), $lapse, $reason)) {
                return false;
            }
            $this->remembered?->forgetOrigin("https://{$domain->host}");
            $this->announce($domain, TenantDomainStatus::Deactivated, TenantChange::DomainLapsed, $reason, $lapse, $now, null);

            return true;
        }));
    }

    /** Releases a lapsed claim, recording and announcing it. */
    private function releaseClaim(TenantDomain $domain, CarbonImmutable $now): bool
    {
        $reason = 'It was out of service for ' . intdiv($this->release, 86_400) . ' days and was released. To use it again, publish the new record shown and check it.';

        return $this->within($domain->tenant, function () use ($domain, $now, $reason): bool {
            if (!$this->domains->release($domain, $now, $reason)) {
                return false;
            }
            $this->announce($domain, TenantDomainStatus::Pending, TenantChange::DomainReleased, $reason, null, $now, null);

            return true;
        });
    }

    /**
     * A write the framework's re-verification makes by host, with the change
     * of state it made recorded and announced.
     *
     * @param \Closure(): void $write
     */
    private function observed(string $host, \Closure $write, bool $forget = false): void
    {
        $before = $this->domains->holding($host);
        if ($before === null) {
            return;
        }

        $this->within($before->tenant, function () use ($before, $write, $forget): void {
            $write();
            $after = $this->domains->ofTenant($before->tenant, $before->id);
            if ($after === null || $after->status === $before->status) {
                return;
            }
            if ($forget && !$after->status->active()) {
                $this->remembered?->forgetOrigin("https://{$before->host}");
            }
            $change = match ($after->status) {
                TenantDomainStatus::Failing => TenantChange::DomainFailing,
                TenantDomainStatus::Deactivated => TenantChange::DomainLapsed,
                default => TenantChange::DomainRestored,
            };
            $this->announce($before, $after->status, $change, $after->reason, $after->lapse, $after->checkedAt ?? $this->now(), null);
        });
    }

    private function announce(
        TenantDomain $domain,
        TenantDomainStatus $to,
        TenantChange $change,
        string $reason,
        ?DomainLapse $lapse,
        CarbonImmutable $at,
        ?Authenticatable $actor,
    ): void {
        $this->activity->record($change, $domain->tenant, $actor, array_filter(
            ['host' => $domain->host, 'from' => $domain->status->value, 'to' => $to->value, 'lapse' => $lapse->value ?? ''],
            static fn (string $value): bool => $value !== '',
        ));
        $this->events?->dispatch(new TenantDomainChanged($domain->tenant, $domain->id, $domain->host, $domain->status, $to, $change, $reason, $lapse, $at));
    }

    /**
     * When the schedule next checks a domain left in $status at $at: while
     * failing, within the hour, and no later than the second after its
     * grace ends, so it lapses then if nothing changed; otherwise an
     * interval out, less up to an eighth of it by the domain's id, so
     * domains checked together drift apart rather than meet at one minute
     * every day.
     */
    private function next(
        TenantDomain $domain,
        CarbonImmutable $at,
        TenantDomainStatus $status,
        ?CarbonImmutable $failingSince = null,
        ?CarbonImmutable $unprovedSince = null,
    ): CarbonImmutable {
        if ($status === TenantDomainStatus::Failing) {
            $next = [$at->addSeconds(min($this->interval, self::FAILING_INTERVAL))];
            if ($failingSince !== null) {
                $next[] = $failingSince->addSeconds($this->grace + 1);
            }
            if ($unprovedSince !== null) {
                $next[] = $unprovedSince->addSeconds($this->proofGrace + 1);
            }

            return max($at->addSecond(), min($next));
        }
        $spread = intdiv($this->interval, 8);

        return $at->addSeconds($this->interval - ($spread > 0 ? crc32($domain->id) % $spread : 0));
    }

    /**
     * $work as $tenant, in a transaction -- a savepoint inside an action's --
     * so a record that cannot be written takes the change back with it.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function within(string $tenant, \Closure $work): mixed
    {
        return $this->orm->actAs($tenant, fn (): mixed => $this->orm->statements()->transaction($work));
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function asNobody(\Closure $work): mixed
    {
        return $this->auth === null ? $work() : $this->auth->actingAs(null, $work);
    }
}
