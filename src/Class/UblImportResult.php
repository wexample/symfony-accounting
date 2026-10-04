<?php

namespace Wexample\SymfonyAccounting\Class;

use Wexample\SymfonyAccounting\Entity\Invoice;

final readonly class UblImportResult
{
    /**
     * @param list<string> $warnings What a person should look at before recording it.
     */
    public function __construct(
        public Invoice $invoice,
        public bool $partyCreated,
        public array $warnings = [],
    ) {
    }
}
