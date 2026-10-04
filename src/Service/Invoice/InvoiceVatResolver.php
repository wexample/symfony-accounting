<?php

namespace Wexample\SymfonyAccounting\Service\Invoice;

use Wexample\SymfonyAccounting\Class\VatCode;
use Wexample\SymfonyAccounting\Class\VatContext;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;

/**
 * Decides the tax situation of each item from who sells to whom, and gives it its
 * VAT code. An item that does not carry VAT (intra-EU, export, franchise, reverse
 * charge…) gets a zero rate, so the document's totals stay right.
 */
class InvoiceVatResolver
{
    public function __construct(
        private readonly JurisdictionRegistry $jurisdictionRegistry,
    ) {
    }

    public function apply(Invoice $invoice): void
    {
        foreach ($invoice->getItems() as $item) {
            $this->applyToItem($invoice, $item);
        }
    }

    public function applyToItem(
        Invoice $invoice,
        InvoiceItem $item
    ): VatCode {
        $ledger = $invoice->getLedger();
        $party = $invoice->getParty();
        $jurisdiction = $this->jurisdictionRegistry->forLedger($ledger);

        $code = $jurisdiction->resolveVatCode(
            new VatContext(
                direction: $invoice->getDirection(),
                ledgerCountryCode: (string) $ledger->getCountryCode(),
                ledgerVatSubject: $ledger->isVatSubject(),
                partyCountryCode: $party?->getCountryCode(),
                partyHasVatNumber: (bool) $party?->hasVatNumber(),
                partyIsIndividual: (bool) $party?->isIndividual(),
                goods: $item->isGoods(),
            ),
            $item->getPriceVat()
        );

        $item->setVat($code->code, $code->kind);

        if (! $code->isCharged() && 0 !== $item->getPriceVat()) {
            // Self-assessed or exempt: nothing is charged on the document.
            $item->setPriceVat(0);
        }

        return $code;
    }
}
