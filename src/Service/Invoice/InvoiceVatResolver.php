<?php

namespace Wexample\SymfonyAccounting\Service\Invoice;

use Wexample\SymfonyAccounting\Class\VatCode;
use Wexample\SymfonyAccounting\Class\VatContext;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Enum\VatKind;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyAccounting\Service\Vat\OssService;

/**
 * Decides the tax situation of each item from who sells to whom, and gives it its
 * VAT code. An item that does not carry VAT (intra-EU, export, franchise, reverse
 * charge…) gets a zero rate, so the document's totals stay right.
 */
class InvoiceVatResolver
{
    public function __construct(
        private readonly JurisdictionRegistry $jurisdictionRegistry,
        private readonly OssService $ossService,
    ) {
    }

    public function apply(Invoice $invoice): void
    {
        $ossApplies = $this->ossService->applies($invoice);

        foreach ($invoice->getItems() as $item) {
            $this->applyToItem($invoice, $item, $ossApplies);
        }
    }

    /**
     * @param bool|null $ossApplies Whether the document is taxed through the One-Stop Shop; asked when null.
     */
    public function applyToItem(
        Invoice $invoice,
        InvoiceItem $item,
        ?bool $ossApplies = null
    ): VatCode {
        $ledger = $invoice->getLedger();
        $party = $invoice->getParty();
        $jurisdiction = $this->jurisdictionRegistry->forLedger($ledger);

        $code = $jurisdiction->resolveVatCode(
            new VatContext(
                direction: $invoice->getDirection(),
                ledgerCountryCode: (string) $ledger->getCountry()?->getIsoAlpha2Code(),
                ledgerVatSubject: $ledger->isVatSubject(),
                partyCountryCode: $party?->getCountry()?->getIsoAlpha2Code(),
                partyHasVatNumber: (bool) $party?->hasVatNumber(),
                partyIsIndividual: (bool) $party?->isIndividual(),
                goods: $item->isGoods(),
                domesticReverseCharge: $item->isDomesticReverseCharge(),
                ossApplies: ($ossApplies ?? $this->ossService->applies($invoice)) && $this->ossService->isEligible($ledger, $item),
            ),
            $item->getPriceVat()
        );

        $item->setVat($code->code, $code->kind);

        if (VatKind::Oss === $code->kind) {
            // Charged at the rate of the customer's country.
            $item->setPriceVat($code->rate);
        }

        if (! $code->isCharged() && 0 !== $item->getPriceVat()) {
            // Self-assessed or exempt: nothing is charged on the document.
            $item->setPriceVat(0);
        }

        return $code;
    }
}
