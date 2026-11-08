<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

/** What one look at a domain's ownership record found (DomainOwnership::check(), ADR 0198). */
enum OwnershipProof: string
{
    /** The record is there, carrying the claim's token. */
    case Proved = 'proved';

    /**
     * DNS answered, and the token is not in it: the name does not exist
     * (NXDOMAIN), it has no TXT record there, or none carries this claim's
     * token. The only answer that counts against a domain in service.
     */
    case Missing = 'missing';

    /**
     * The lookup did not complete -- a server failure, a timeout. It says
     * nothing of who owns the name, so it neither counts against a domain
     * nor ends a run of misses it falls in.
     */
    case Unreadable = 'unreadable';
}
