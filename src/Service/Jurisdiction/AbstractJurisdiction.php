<?php

namespace Wexample\SymfonyAccounting\Service\Jurisdiction;

use Wexample\SymfonyAccounting\Class\LatePenaltyPolicy;
use Wexample\SymfonyAccounting\Class\LegalMention;
use Wexample\SymfonyAccounting\Class\ReportDefinition;
use Wexample\SymfonyAccounting\Class\VatCode;
use Wexample\SymfonyAccounting\Class\VatContext;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Enum\VatKind;
use Wexample\SymfonyAccounting\Helper\EuHelper;
use Wexample\SymfonyAccounting\Helper\EuVatRateHelper;
use Wexample\SymfonyAccounting\Interface\JurisdictionInterface;

/**
 * Sensible defaults for an EU country: VAT kinds follow the common EU rules,
 * codes are generated from direction, kind and rate, accounts with a
 * debit/credit-side class layout (1–5 balance sheet, 6 charges, 7 income).
 */
abstract class AbstractJurisdiction implements JurisdictionInterface
{
    /**
     * @return array<string, string> AccountRole value → account number.
     */
    abstract protected function getAccountNumbers(): array;



    public function getVatDepositSchedule(
        Ledger $ledger,
        int $year,
        int $previousNetVat
    ): array {
        return [];
    }

    public function getBankLocalDetails(string $iban): array
    {
        return [];
    }

    public function isFacturXEmbedded(Ledger $ledger): bool
    {
        return false;
    }




    public function getChartDatasets(): array
    {
        return [];
    }

    public function getChartAccounts(string $dataset): iterable
    {
        return [];
    }

    public function getDefaultChartDataset(): ?string
    {
        return $this->getChartDatasets()[0] ?? null;
    }

    public function getAccountNumber(AccountRole $role): ?string
    {
        return $this->getAccountNumbers()[$role->value] ?? null;
    }

    public function getDefaultJournals(): array
    {
        return [
            'VE' => ['label' => 'Sales', 'type' => JournalType::Sales],
            'AC' => ['label' => 'Purchases', 'type' => JournalType::Purchases],
            'BQ' => ['label' => 'Bank', 'type' => JournalType::Bank],
            'CA' => ['label' => 'Cash', 'type' => JournalType::Cash],
            'OD' => ['label' => 'Miscellaneous operations', 'type' => JournalType::Miscellaneous],
            'AN' => ['label' => 'Opening balances', 'type' => JournalType::Opening],
        ];
    }

    /**
     * The French-style rule most charts share: "28" followed by the asset account
     * without its class digit.
     */
    public function getDepreciationAccountNumber(string $assetAccountNumber): string
    {
        return '28'.substr($assetAccountNumber, 1);
    }

    /**
     * Class 2 of the French and Belgian charts, depreciation (28) and
     * impairment (29) aside.
     */
    public function isFixedAssetAccount(string $accountNumber): bool
    {
        return str_starts_with($accountNumber, '2')
            && ! str_starts_with($accountNumber, '28')
            && ! str_starts_with($accountNumber, '29');
    }

    public function isBalanceSheetAccount(string $accountNumber): bool
    {
        return in_array((int) ($accountNumber[0] ?? 0), [1, 2, 3, 4, 5], true);
    }

    public function isIncomeAccount(string $accountNumber): ?bool
    {
        return match ((int) ($accountNumber[0] ?? 0)) {
            7 => true,
            6 => false,
            default => null,
        };
    }

    /**
     * The common EU logic. Distance-selling thresholds and OSS are not handled:
     * a sale to an EU private person is treated as domestic.
     */
    public function resolveVatKind(VatContext $context): VatKind
    {
        $sale = InvoiceDirection::Sale === $context->direction;
        $partyCountry = $context->partyCountryCode ? strtoupper($context->partyCountryCode) : null;
        $sameCountry = null === $partyCountry || strtoupper($context->ledgerCountryCode) === $partyCountry;
        $partyBusiness = $context->partyHasVatNumber && ! $context->partyIsIndividual;

        if ($sale) {
            if (! $context->ledgerVatSubject) {
                return VatKind::Franchise;
            }

            if ($sameCountry) {
                return $context->domesticReverseCharge && $partyBusiness ? VatKind::DomesticReverseCharge : VatKind::Domestic;
            }

            if (EuHelper::isMemberState($partyCountry)) {
                if (! $partyBusiness) {
                    // A consumer: the seller's VAT, until the One-Stop Shop applies.
                    return $context->ossApplies ? VatKind::Oss : VatKind::Domestic;
                }

                return $context->goods ? VatKind::IntraEuGoods : VatKind::IntraEuServices;
            }

            return VatKind::Export;
        }

        if ($sameCountry) {
            return $context->domesticReverseCharge && $context->ledgerVatSubject ? VatKind::DomesticReverseCharge : VatKind::Domestic;
        }

        if (! $context->ledgerVatSubject) {
            // Not liable: foreign VAT is a cost, nothing is self-assessed.
            return VatKind::OutOfScope;
        }

        if (EuHelper::isMemberState($partyCountry) && $context->goods) {
            return VatKind::IntraEuAcquisition;
        }

        if ($context->goods) {
            return VatKind::Domestic;
        }

        // Services bought abroad: self-assessed, from the EU or from elsewhere, which forms report apart.
        return EuHelper::isMemberState($partyCountry) ? VatKind::ReverseCharge : VatKind::ImportedServices;
    }

    public function resolveVatCode(
        VatContext $context,
        int $rate
    ): VatCode {
        $kind = $this->resolveVatKind($context);

        if (VatKind::Oss === $kind) {
            $country = strtoupper((string) $context->partyCountryCode);

            return $this->buildOssVatCode($country, EuVatRateHelper::getStandardRate($country) ?? $rate);
        }

        // A company not liable for VAT pays its suppliers' VAT as a cost.
        if (InvoiceDirection::Purchase === $context->direction && ! $context->ledgerVatSubject && VatKind::Domestic === $kind && $rate > 0) {
            return new VatCode('P_NDED_'.$rate, $rate, InvoiceDirection::Purchase, VatKind::Domestic, null);
        }

        return $this->buildVatCode($context->direction, $kind, $rate);
    }

    public function getVatCode(string $code): ?VatCode
    {
        if (preg_match('/^P_NDED_(\d+)$/', $code, $matches)) {
            return new VatCode($code, (int) $matches[1], InvoiceDirection::Purchase, VatKind::Domestic, null);
        }

        if (preg_match('/^S_OSS_([A-Z]{2})_(\d+)$/', $code, $matches)) {
            return $this->buildOssVatCode($matches[1], (int) $matches[2]);
        }

        if (! preg_match('/^([SP])_([A-Z_]+)_(\d+)$/', $code, $matches)) {
            return null;
        }

        $direction = 'S' === $matches[1] ? InvoiceDirection::Sale : InvoiceDirection::Purchase;
        $kind = array_search($matches[2], self::KIND_CODES, true);

        return false === $kind ? null : $this->buildVatCode($direction, VatKind::from($kind), (int) $matches[3]);
    }

    private const array KIND_CODES = [
        'domestic' => 'DOM',
        'intra_eu_goods' => 'IEUG',
        'intra_eu_services' => 'IEUS',
        'export' => 'EXP',
        'intra_eu_acquisition' => 'IEUA',
        'reverse_charge' => 'RC',
        'imported_services' => 'RCX',
        'domestic_reverse_charge' => 'DRC',
        'exempt' => 'EXE',
        'franchise' => 'FRA',
        'out_of_scope' => 'OUT',
        'oss' => 'OSS',
    ];

    public function buildVatCode(
        InvoiceDirection $direction,
        VatKind $kind,
        int $rate
    ): VatCode {
        $taxed = match ($kind) {
            VatKind::Domestic => $rate > 0,
            VatKind::IntraEuAcquisition, VatKind::ReverseCharge, VatKind::ImportedServices, VatKind::DomesticReverseCharge => InvoiceDirection::Purchase === $direction,
            default => false,
        };
        $rate = $taxed ? $rate : 0;
        $code = (InvoiceDirection::Sale === $direction ? 'S' : 'P').'_'.self::KIND_CODES[$kind->value].'_'.$rate;

        if (! $taxed) {
            return new VatCode($code, 0, $direction, $kind, null);
        }

        if (InvoiceDirection::Sale === $direction) {
            return new VatCode($code, $rate, $direction, $kind, AccountRole::VatCollected);
        }

        return new VatCode(
            $code,
            $rate,
            $direction,
            $kind,
            AccountRole::VatDeductible,
            VatKind::Domestic === $kind ? null : AccountRole::VatSelfAssessed,
        );
    }

    /**
     * A sale taxed in another EU country, its country in the code (`S_OSS_DE_1900`)
     * so that the OSS return reports it there.
     */
    public function buildOssVatCode(
        string $country,
        int $rate
    ): VatCode {
        return new VatCode('S_OSS_'.$country.'_'.$rate, $rate, InvoiceDirection::Sale, VatKind::Oss, AccountRole::VatCollectedOss, country: $country);
    }

    /**
     * Generic mentions: why there is no VAT, and the late-payment terms of bills.
     */
    public function getInvoiceMentions(Invoice $invoice): array
    {
        if (InvoiceDirection::Sale !== $invoice->getDirection()) {
            return [];
        }

        $mentions = [];

        foreach ($invoice->getVatKinds() as $kind) {
            $key = match ($kind) {
                VatKind::Franchise => 'mention.vat.franchise',
                VatKind::IntraEuServices => 'mention.vat.reverse_charge',
                VatKind::DomesticReverseCharge => 'mention.vat.domestic_reverse_charge',
                VatKind::IntraEuGoods => 'mention.vat.intra_eu_goods',
                VatKind::Export => 'mention.vat.export',
                VatKind::Exempt => 'mention.vat.exempt',
                default => null,
            };

            if ($key) {
                $mentions[] = new LegalMention($key, LegalMention::PLACEMENT_VAT);
            }
        }

        if (in_array($invoice->getType(), [InvoiceType::Bill, InvoiceType::Penalty], true)) {
            $policy = $this->getLatePenaltyPolicy($invoice->getLedger());
            $mentions[] = new LegalMention('mention.payment.late_penalties', LegalMention::PLACEMENT_PAYMENT, [
                'rate' => $policy->rate / 100,
                'flat_fee' => $policy->flatFee / 100,
                'days' => $invoice->getPaymentTermDays(),
            ]);
        }

        return $mentions;
    }

    public function getLatePenaltyPolicy(Ledger $ledger): LatePenaltyPolicy
    {
        return new LatePenaltyPolicy(
            mode: $ledger->getSetting('late_penalty_mode', LatePenaltyPolicy::MODE_ANNUAL),
            rate: (int) $ledger->getSetting('late_penalty_rate', 1200),
            flatFee: (int) $ledger->getSetting('late_penalty_flat_fee', 4000),
        );
    }

    public function generatePaymentReference(Invoice $invoice): ?string
    {
        return null;
    }

    public function extractPaymentReferences(string $label): array
    {
        return [];
    }

    public function getReportDefinitions(): array
    {
        return [
            ReportDefinition::fromArray([
                'key' => 'balance_sheet',
                'label' => 'report.balance_sheet',
                'lines' => [
                    ['key' => 'fixed_assets', 'label' => 'report.fixed_assets', 'accounts' => ['2'], 'deduct' => ['28', '29'], 'level' => 1],
                    ['key' => 'stocks', 'label' => 'report.stocks', 'accounts' => ['3'], 'deduct' => ['39'], 'level' => 1],
                    ['key' => 'receivables', 'label' => 'report.receivables', 'accounts' => ['4D'], 'level' => 1],
                    ['key' => 'cash', 'label' => 'report.cash', 'accounts' => ['5D'], 'level' => 1],
                    ['key' => 'assets', 'label' => 'report.assets', 'formula' => 'fixed_assets+stocks+receivables+cash'],
                    ['key' => 'equity', 'label' => 'report.equity', 'accounts' => ['1!15!16!17!18'], 'sign' => -1, 'level' => 1],
                    // The result of a year not closed into equity yet.
                    ['key' => 'year_result', 'label' => 'report.year_result', 'accounts' => ['6', '7'], 'sign' => -1, 'level' => 1],
                    ['key' => 'provisions', 'label' => 'report.provisions', 'accounts' => ['15'], 'sign' => -1, 'level' => 1],
                    ['key' => 'debts', 'label' => 'report.debts', 'accounts' => ['16', '17', '18', '4C', '5C'], 'sign' => -1, 'level' => 1],
                    ['key' => 'liabilities', 'label' => 'report.liabilities', 'formula' => 'equity+year_result+provisions+debts'],
                ],
            ]),
            ReportDefinition::fromArray([
                'key' => 'income_statement',
                'label' => 'report.income_statement',
                'lines' => [
                    ['key' => 'income', 'label' => 'report.income', 'accounts' => ['7'], 'sign' => -1, 'level' => 1],
                    ['key' => 'charges', 'label' => 'report.charges', 'accounts' => ['6'], 'level' => 1],
                    ['key' => 'result', 'label' => 'report.result', 'formula' => 'income-charges'],
                ],
            ]),
        ];
    }
}
