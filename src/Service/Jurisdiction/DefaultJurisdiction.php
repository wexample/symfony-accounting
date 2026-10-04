<?php

namespace Wexample\SymfonyAccounting\Service\Jurisdiction;

use Wexample\SymfonyAccounting\Class\ChartAccountDefinition;
use Wexample\SymfonyAccounting\Enum\AccountNature;

/**
 * Used when no package covers the ledger's country: a minimal chart following the
 * class layout most European charts share. Accounts missing from it are created
 * on first use with a generic label.
 */
class DefaultJurisdiction extends AbstractJurisdiction
{
    public const string DATASET = 'generic';

    public function getCountryCode(): ?string
    {
        return null;
    }

    public function getVatRates(): array
    {
        return [2000, 1000, 500, 0];
    }

    protected function getAccountNumbers(): array
    {
        return [
            'customers' => '411',
            'customer_advances' => '419',
            'suppliers' => '401',
            'sales_goods' => '707',
            'sales_services' => '706',
            'purchases' => '607',
            'purchases_services' => '61',
            'bank' => '512',
            'cash' => '530',
            'internal_transfer' => '580',
            'payment_provider' => '517',
            'bank_fees' => '627',
            'vat_collected' => '4457',
            'vat_collected_pending' => '4458',
            'vat_deductible' => '4456',
            'vat_deductible_pending' => '4459',
            'vat_deductible_assets' => '4452',
            'vat_self_assessed' => '4454',
            'vat_payable' => '4455',
            'vat_credit' => '4453',
            'vat_deposits' => '4451',
            'doubtful_customers' => '416',
            'bad_debts' => '654',
            'late_penalties_income' => '763',
            'depreciation_expense' => '681',
            'asset_disposal_value' => '675',
            'prepaid_expenses' => '486',
            'deferred_income' => '487',
            'rounding_gain' => '758',
            'rounding_loss' => '658',
            'result_profit' => '120',
            'result_loss' => '129',
            'retained_earnings' => '110',
            'retained_losses' => '119',
            'owner_account' => '108',
            'suspense' => '471',
        ];
    }

    public function getChartDatasets(): array
    {
        return [self::DATASET];
    }

    public function getChartAccounts(string $dataset): iterable
    {
        if (self::DATASET !== $dataset) {
            return;
        }

        $labels = [
            '101' => ['Capital', AccountNature::Credit],
            '108' => ['Owner account', AccountNature::Both],
            '110' => ['Retained earnings', AccountNature::Credit],
            '119' => ['Retained losses', AccountNature::Debit],
            '120' => ['Result: profit', AccountNature::Credit],
            '129' => ['Result: loss', AccountNature::Debit],
            '164' => ['Bank loans', AccountNature::Credit],
            '218' => ['Equipment', AccountNature::Debit],
            '281' => ['Depreciation of equipment', AccountNature::Credit],
            '401' => ['Suppliers', AccountNature::Credit],
            '411' => ['Customers', AccountNature::Debit],
            '416' => ['Doubtful customers', AccountNature::Debit],
            '4451' => ['VAT deposits', AccountNature::Debit],
            '4452' => ['Deductible VAT on fixed assets', AccountNature::Debit],
            '4453' => ['VAT credit', AccountNature::Debit],
            '4454' => ['Self-assessed VAT', AccountNature::Credit],
            '4455' => ['VAT payable', AccountNature::Credit],
            '4456' => ['Deductible VAT', AccountNature::Debit],
            '4457' => ['Collected VAT', AccountNature::Credit],
            '4458' => ['Collected VAT, due on payment', AccountNature::Credit],
            '4459' => ['Deductible VAT, due on payment', AccountNature::Debit],
            '471' => ['Suspense account', AccountNature::Both],
            '512' => ['Bank', AccountNature::Both],
            '517' => ['Payment providers', AccountNature::Both],
            '530' => ['Cash', AccountNature::Debit],
            '580' => ['Internal transfers', AccountNature::Both],
            '607' => ['Purchases of goods', AccountNature::Debit],
            '61' => ['External services', AccountNature::Debit],
            '627' => ['Bank fees', AccountNature::Debit],
            '64' => ['Staff costs', AccountNature::Debit],
            '654' => ['Bad debts', AccountNature::Debit],
            '658' => ['Other operating charges', AccountNature::Debit],
            '681' => ['Depreciation charges', AccountNature::Debit],
            '706' => ['Sales of services', AccountNature::Credit],
            '707' => ['Sales of goods', AccountNature::Credit],
            '758' => ['Other operating income', AccountNature::Credit],
            '763' => ['Late payment interest', AccountNature::Credit],
        ];

        foreach ($labels as $number => [$label, $nature]) {
            yield new ChartAccountDefinition(
                number: (string) $number,
                label: $label,
                nature: $nature,
                lettrable: in_array((string) $number, ['401', '411', '416'], true),
            );
        }
    }
}
