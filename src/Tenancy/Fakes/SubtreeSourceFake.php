<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\Fakes;

use Tinvar\Tenancy\SubtreeSource;

/**
 * TenantSourceFake as a host is: its answer bounds the request, so one
 * already running as a tenant in that subtree goes on as that tenant
 * (SubtreeSource). The subtree is the ORM's to read, from the tree the
 * test's application was given.
 */
final class SubtreeSourceFake extends TenantSourceFake implements SubtreeSource {}
