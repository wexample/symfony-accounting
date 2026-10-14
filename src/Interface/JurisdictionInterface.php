<?php

namespace Wexample\SymfonyAccounting\Interface;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\ChartAccountDefinition;
use Wexample\SymfonyAccounting\Class\LatePenaltyPolicy;
use Wexample\SymfonyAccounting\Class\LegalMention;
use Wexample\SymfonyAccounting\Class\ReportDefinition;
use Wexample\SymfonyAccounting\Class\VatCode;
use Wexample\SymfonyAccounting\Class\VatContext;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Enum\VatKind;

/**
 * Everything a country's law and accounting practice decide, so that the core
 * holds none of it. A ledger uses the jurisdiction of its country; the
 * default one applies when no package covers that country.
 *
 * Extend AbstractJurisdiction rather than implementing this from scratch.
 */
interface JurisdictionInterface
{
    /**
     * ISO 3166-1 alpha-2 code, or null for the default jurisdiction.
     */
    public function getCountryCode(): ?string;

    /**
     * The VAT deposits due in $year, where the regime asks for some (the
     * French simplified regime: July and December), from the previous year's
     * net VAT. Empty elsewhere.
     *
     * @return list<array{date: DateTimeImmutable, amount: int}>
     */
    public function getVatDepositSchedule(
        Ledger $ledger,
        int $year,
        int $previousNetVat
    ): array;

    /**
     * The national form of a bank account printed beside its IBAN (the French RIB):
     * a label (translation key) and a value per part, empty where none is customary.
     *
     * @return list<array{label: string, value: string}>
     */
    public function getBankLocalDetails(string $iban): array;

    /**
     * Whether the PDF of an emitted invoice carries its structured version as
     * Factur-X (CII embedded in a PDF/A-3), the hybrid format of the French reform.
     */
    public function isFacturXEmbedded(Ledger $ledger): bool;

    /**
     * Chart datasets this jurisdiction ships, by key ("fr_pcg", "fr_pca", "be_pcmn").
     *
     * @return list<string>
     */
    public function getChartDatasets(): array;

    /**
     * @return iterable<ChartAccountDefinition>
     */
    public function getChartAccounts(string $dataset): iterable;

    public function getDefaultChartDataset(): ?string;

    public function getAccountNumber(AccountRole $role): ?string;

    /**
     * @return array<string, array{label: string, type: JournalType}> Keyed by journal code.
     */
    public function getDefaultJournals(): array;

    /**
     * Whether an account is carried forward to the next fiscal year (balance sheet)
     * rather than closed into the result (income statement).
     */
    public function isBalanceSheetAccount(string $accountNumber): bool;

    /**
     * Whether an account holds fixed assets, whose deductible VAT is booked
     * and declared apart (AccountRole::VatDeductibleAssets).
     */
    public function isFixedAssetAccount(string $accountNumber): bool;

    /**
     * Whether an account is part of the result: income (true) or charge.
     * Null when it is neither.
     */
    public function isIncomeAccount(string $accountNumber): ?bool;

    /**
     * The account accumulating the depreciation of a fixed asset account
     * (FR 2183 → 28183, BE 2400 → 2409).
     */
    public function getDepreciationAccountNumber(string $assetAccountNumber): string;

    /**
     * @return list<int> Basis points, the normal rate first.
     */
    public function getVatRates(): array;

    public function resolveVatKind(VatContext $context): VatKind;

    public function resolveVatCode(
        VatContext $context,
        int $rate
    ): VatCode;

    public function getVatCode(string $code): ?VatCode;

    /**
     * Mentions the law requires on this document, given who emits it to whom.
     *
     * @return list<LegalMention>
     */
    public function getInvoiceMentions(Invoice $invoice): array;

    public function getLatePenaltyPolicy(Ledger $ledger): LatePenaltyPolicy;

    /**
     * A payment reference the bank will carry back (BE structured communication),
     * or null to use the invoice number.
     */
    public function generatePaymentReference(Invoice $invoice): ?string;

    /**
     * Extracts payment references (as generatePaymentReference() writes them) from a bank label.
     *
     * @return list<string>
     */
    public function extractPaymentReferences(string $label): array;

    /**
     * @return list<ReportDefinition>
     */
    public function getReportDefinitions(): array;
}
