<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Http;

use Tinvar\Attributes\Authorize;
use Tinvar\Http\HttpException;
use Tinvar\Orm\Orm;
use Tinvar\Tenancy\SelfService\Data\TenantDomainData;
use Tinvar\Tenancy\SelfService\TenantAbility;
use Tinvar\Tenancy\SelfService\TenantAdministration;
use Tinvar\Tenancy\SelfService\TenantDomainClaims;

/**
 * The tenant's custom domains as they stand now, ready-made (ADR 0161): the
 * query the domains page's view polls while a domain is pending or failing,
 * so a status changed by the daily check, by Caddy's ask or by another
 * admin shows without a reload. It reads; it checks nothing itself.
 *
 *     #[Query(name: 'tenancy.domains'), TenantFrom(Host::class), Panel('customer')]
 *     final class OrganizationDomainStatuses extends CustomDomainStatuses {}
 *
 * `viewTenantDomains`, as the page.
 */
#[Authorize(TenantAbility::ViewDomains->value, TenantAdministration::class)]
abstract class CustomDomainStatuses
{
    public function __construct(
        protected readonly Orm $orm,
        protected readonly TenantDomainClaims $claims,
    ) {}

    /** @return list<TenantDomainData> */
    final public function __invoke(): array
    {
        $tenant = $this->orm->tenant();
        if ($tenant === '') {
            throw new HttpException(404, 'Open this page on your organization\'s own address.', 'not_found');
        }

        return array_map(
            $this->claims->describe(...),
            $this->claims->of($tenant),
        );
    }
}
