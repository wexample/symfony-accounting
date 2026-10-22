<?php

namespace Wexample\SymfonyAccounting\Class;

use DateTimeImmutable;

/**
 * A bank line read from a file or an API, before it is stored.
 */
final readonly class ParsedTransaction
{
    public function __construct(
        public DateTimeImmutable $date,
        public int $amount,
        public string $label,
        public ?string $externalId = null,
        public ?DateTimeImmutable $valueDate = null,
        public ?string $counterpartyName = null,
        public ?string $counterpartyIban = null,
        public ?string $reference = null,
        public ?string $paymentReference = null,
        public array $metadata = [],
    ) {
    }
}
