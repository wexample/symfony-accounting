<?php

namespace Wexample\SymfonyAccounting\Class;

/**
 * One credit transfer of a SEPA payment file.
 */
final readonly class SepaPayment
{
    public function __construct(
        public string $endToEndId,
        public int $amount,
        public string $creditorName,
        public string $creditorIban,
        public ?string $creditorBic = null,
        public ?string $remittance = null,
        /** A structured creditor reference (BE +++…+++, ISO RF…), sent as such. */
        public ?string $structuredReference = null,
    ) {
    }
}
