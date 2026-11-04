<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Http\HttpException;

/**
 * A host naming a tenant by a slug it has left: Subdomains throws it with
 * the host the tenant is at now, and Host answers the request with a
 * permanent redirect there, its path and query kept.
 *
 * Thrown anywhere else, it is what it extends: the 404 of a host that is
 * no tenant's. A redirect is only ever built by Host, from the request it
 * was asked about, so nothing here can send a visitor off the platform.
 */
final class TenantMoved extends HttpException
{
    public function __construct(public readonly string $host)
    {
        parent::__construct(404, 'This host names a tenant by a slug it has left.', 'tenant_unknown');
    }
}
