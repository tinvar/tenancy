<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Requests;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Pattern;

/** One of the tenant's domains, by id: found among the tenant's own, or a 404. */
final readonly class DomainRequest extends FormRequest
{
    public function __construct(
        #[Pattern('[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', message: 'Choose one of your domains.')]
        public string $domain,
    ) {}
}
