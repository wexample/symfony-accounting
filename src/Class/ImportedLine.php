<?php

namespace Wexample\SymfonyAccounting\Class;

use DateTimeImmutable;

/**
 * One entry line read from another bookkeeping (FEC, CSV), before it is booked.
 * Lines sharing a journal and an entry number form one entry.
 */
final readonly class ImportedLine
{
    public function __construct(
        public string $journalCode,
        public string $entryNumber,
        public DateTimeImmutable $date,
        public string $accountNumber,
        public string $label,
        public int $debit,
        public int $credit,
        public ?string $journalLabel = null,
        public ?string $accountLabel = null,
        public ?string $auxiliaryCode = null,
        public ?string $auxiliaryLabel = null,
        public ?string $pieceReference = null,
        public ?DateTimeImmutable $pieceDate = null,
        public ?string $letter = null,
        public ?DateTimeImmutable $dateLettered = null,
        public ?DateTimeImmutable $dateValidated = null,
        public ?int $currencyAmount = null,
        public ?string $currencyCode = null,
    ) {
    }
}
