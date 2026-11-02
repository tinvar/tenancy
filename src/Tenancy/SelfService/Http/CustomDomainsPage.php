<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Http;

use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Gate;
use Tinvar\Orm\Orm;
use Tinvar\Tenancy\SelfService\Data\DnsRecordData;
use Tinvar\Tenancy\SelfService\Data\TenantDomainData;
use Tinvar\Tenancy\SelfService\Data\TenantDomainsPageData;
use Tinvar\Tenancy\SelfService\Requests\AddDomainRequest;
use Tinvar\Tenancy\SelfService\Requests\DomainRequest;
use Tinvar\Tenancy\SelfService\TenantAbility;
use Tinvar\Tenancy\SelfService\TenantAdministration;
use Tinvar\Tenancy\SelfService\TenantDomain;
use Tinvar\Tenancy\SelfService\TenantDomainClaims;

/**
 * A tenant's custom domains, ready-made (ADR 0155): the page where a tenant
 * admin adds one, sees the DNS records to create for it and its status --
 * pending, verified, failing or deactivated -- checks it, and removes it.
 *
 *     #[Page('/settings/domains', view: 'tenancy/domains', name: 'tenancy.domains'),
 *       Domain('{tenant}.{root}'), TenantFrom(Host::class), Panel('customer')]
 *     final class OrganizationDomains extends CustomDomainsPage {}
 *
 * Seeing it is `viewTenantDomains`; adding, checking and removing are
 * `manageTenantDomains` too, each on its final action -- `<Class>.add`,
 * `<Class>.check` and `<Class>.remove` -- so a subclass changes where the
 * page is and how it looks, never what it checks. Everything is the
 * tenant's the request runs as; a domain id from another tenant is a 404.
 *
 * Its view watches a pending domain with CustomDomainStatuses, a query the
 * application declares beside it, or by checking it with `<Class>.check`.
 */
#[Authorize(TenantAbility::ViewDomains->value, TenantAdministration::class)]
abstract class CustomDomainsPage extends SelfServicePage
{
    public function __construct(
        Orm $orm,
        Gate $gate,
        protected readonly TenantDomainClaims $claims,
        protected readonly AuthContext $auth,
    ) {
        parent::__construct($orm, $gate);
    }

    final public function __invoke(): TenantDomainsPageData
    {
        $tenant = $this->tenant();

        return new TenantDomainsPageData(
            array_map($this->data(...), $this->claims->of($tenant)),
            $this->claims->limit(),
            $this->may(TenantAbility::ManageDomains),
        );
    }

    /** Claims a domain: pending, until its records are there and a check proves them. */
    #[Action(transaction: true), Authorize(TenantAbility::ManageDomains->value, TenantAdministration::class)]
    final public function add(AddDomainRequest $input): ActionResult
    {
        $domain = $this->claims->add($this->tenant(), $input->host, $this->auth->user());

        return $this->again(['domain' => $domain->id]);
    }

    /** Checks a domain now: its ownership and DNS, or -- once in service -- its re-verification. */
    #[Action(transaction: true), Authorize(TenantAbility::ManageDomains->value, TenantAdministration::class)]
    final public function check(DomainRequest $input): ActionResult
    {
        $domain = $this->claims->check($this->tenant(), $input->domain, $this->auth->user());

        return $this->again(['status' => $domain->status->value]);
    }

    /** Removes a domain: it is no longer served, from the next request on. */
    #[Action(transaction: true), Authorize(TenantAbility::ManageDomains->value, TenantAdministration::class)]
    final public function remove(DomainRequest $input): ActionResult
    {
        $this->claims->remove($this->tenant(), $input->domain, $this->auth->user());

        return $this->again();
    }

    private function data(TenantDomain $domain): TenantDomainData
    {
        return TenantDomainData::of($domain, array_map(DnsRecordData::of(...), $this->claims->records($domain)));
    }
}
