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

    public function getLegalIdentifierLabel(): string
    {
        return 'identity.legal_identifier';
    }

    public function getVatNumberLabel(): string
    {
        return 'identity.vat_number';
    }

    public function isLegalIdentifierIncludedInVatNumber(): bool
    {
        return false;
    }

    public function isLegalIdentifierValid(string $identifier): bool
    {
        return '' !== trim($identifier);
    }

    public function isVatNumberValid(string $vatNumber): bool
    {
        return 1 === preg_match('/^[A-Z]{2}[0-9A-Z]{2,13}$/', strtoupper(preg_replace('/[\s.\-]/', '', $vatNumber)));
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
                return VatKind::Domestic;
            }

            if (EuHelper::isMemberState($partyCountry)) {
                if (! $partyBusiness) {
                    return VatKind::Domestic;
                }

                return $context->goods ? VatKind::IntraEuGoods : VatKind::IntraEuServices;
            }

            return VatKind::Export;
        }

        if ($sameCountry) {
            return VatKind::Domestic;
        }

        if (! $context->ledgerVatSubject) {
            // Not liable: foreign VAT is a cost, nothing is self-assessed.
            return VatKind::OutOfScope;
        }

        if (EuHelper::isMemberState($partyCountry) && $context->goods) {
            return VatKind::IntraEuAcquisition;
        }

        // Services bought abroad, from the EU or elsewhere: reverse charge.
        return $context->goods ? VatKind::Domestic : VatKind::ReverseCharge;
    }

    public function resolveVatCode(
        VatContext $context,
        int $rate
    ): VatCode {
        $kind = $this->resolveVatKind($context);

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
        'exempt' => 'EXE',
        'franchise' => 'FRA',
        'out_of_scope' => 'OUT',
    ];

    public function buildVatCode(
        InvoiceDirection $direction,
        VatKind $kind,
        int $rate
    ): VatCode {
        $taxed = match ($kind) {
            VatKind::Domestic => $rate > 0,
            VatKind::IntraEuAcquisition, VatKind::ReverseCharge => InvoiceDirection::Purchase === $direction,
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
