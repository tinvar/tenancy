<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService\Data;

use Tinvar\Data\Data;
use Tinvar\Tenancy\SelfService\DnsRecord;

/** A DNS record to create, as the domains page lists it. */
final readonly class DnsRecordData extends Data
{
    public function __construct(
        public string $type,
        public string $name,
        public string $value,
        public string $purpose,
    ) {}

    public static function of(DnsRecord $record): self
    {
        return new self($record->type, $record->name, $record->value, $record->purpose);
    }
}
