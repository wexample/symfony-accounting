<?php

namespace Wexample\SymfonyAccounting\Service\Vat;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\VatContext;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyAccounting\Service\Ledger\SalesLedgerProvider;
use Wexample\SymfonyMoney\Interface\VatRateResolverInterface;
use Wexample\SymfonyOrganization\Service\VatNumberVerifier;

/**
 * The VAT a buyer is charged at checkout, by the rules of the sales ledger's
 * country, as its invoice will carry it: none for a business from another EU
 * country whose number VIES confirms, the buyer's country's rate for a consumer
 * once the One-Stop Shop applies, the seller's own otherwise.
 *
 * Takes the place of money's default resolver; without a sales ledger, or before
 * the buyer's country is known, that one still answers.
 */
class AccountingVatRateResolver implements VatRateResolverInterface
{
    public function __construct(
        private readonly VatRateResolverInterface $inner,
        private readonly SalesLedgerProvider $salesLedgerProvider,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
        private readonly OssService $ossService,
        private readonly VatNumberVerifier $vatNumberVerifier,
    ) {
    }

    public function resolveVatRate(
        int $rate,
        bool $goods,
        ?string $buyerCountryCode,
        ?string $buyerVatNumber
    ): int {
        $ledger = $this->salesLedgerProvider->getLedger();

        if (null === $ledger || null === $buyerCountryCode) {
            return $this->inner->resolveVatRate($rate, $goods, $buyerCountryCode, $buyerVatNumber);
        }

        $business = null !== $buyerVatNumber && '' !== trim($buyerVatNumber) && $this->vatNumberVerifier->isConfirmed($buyerVatNumber);
        $code = $this->jurisdictionRegistry->forLedger($ledger)->resolveVatCode(
            new VatContext(
                direction: InvoiceDirection::Sale,
                ledgerCountryCode: (string) $ledger->getCountry()?->getIsoAlpha2Code(),
                ledgerVatSubject: $ledger->isVatSubject(),
                partyCountryCode: $buyerCountryCode,
                partyHasVatNumber: $business,
                partyIsIndividual: ! $business,
                goods: $goods,
                ossApplies: ($goods || $ledger->getSetting('oss_electronic_services', false))
                    && $this->ossService->appliesToLedger($ledger, new DateTimeImmutable()),
            ),
            $rate
        );

        return $code->isCharged() ? $code->rate : 0;
    }
}
