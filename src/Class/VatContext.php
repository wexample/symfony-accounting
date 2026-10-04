<?php

namespace Wexample\SymfonyAccounting\Class;

use Wexample\SymfonyAccounting\Enum\InvoiceDirection;

/**
 * Who sells to whom, from where to where: what decides the tax situation of an item.
 */
final readonly class VatContext
{
    public function __construct(
        public InvoiceDirection $direction,
        /** Country of the ledger's company. */
        public string $ledgerCountryCode,
        public bool $ledgerVatSubject,
        /** Country of the customer (sale) or supplier (purchase). */
        public ?string $partyCountryCode,
        public bool $partyHasVatNumber,
        public bool $partyIsIndividual,
        /** Goods (true) or services (false). */
        public bool $goods = false,
    ) {
    }
}
