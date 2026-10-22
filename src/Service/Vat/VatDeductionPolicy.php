<?php

namespace Wexample\SymfonyAccounting\Service\Vat;

use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyMoney\Helper\RateHelper;

/**
 * How much of the VAT a ledger pays it may deduct, in basis points:
 *
 * - the deduction coefficient of the year (FR: assujettissement × taxation, BE:
 *   prorata), setting `vat_deduction_rates` by year, else `vat_deduction_rate`,
 *   else all of it. Provisional during the year, made final by
 *   VatDeductionRegularizationService;
 * - the admission of an expense account (receptions, passenger cars…), setting
 *   `vat_admission_rates`, by account prefix, the longest prefix winning.
 *
 * What may not be deducted is a cost, booked with the expense it comes with.
 */
class VatDeductionPolicy
{
    public function getDeductionRate(
        Ledger $ledger,
        int $year
    ): int {
        return (int) ($ledger->getSetting('vat_deduction_rates', [])[$year] ?? $ledger->getSetting('vat_deduction_rate', RateHelper::BASIS));
    }

    public function getAdmissionRate(
        Ledger $ledger,
        string $accountNumber
    ): int {
        $rate = RateHelper::BASIS;
        $longest = -1;

        foreach ($ledger->getSetting('vat_admission_rates', []) as $prefix => $admission) {
            $prefix = (string) $prefix;

            if (str_starts_with($accountNumber, $prefix) && strlen($prefix) > $longest) {
                $rate = (int) $admission;
                $longest = strlen($prefix);
            }
        }

        return $rate;
    }

    /**
     * Whether anything the ledger pays is less than fully deductible.
     */
    public function isPartial(
        Ledger $ledger,
        int $year
    ): bool {
        return $this->getDeductionRate($ledger, $year) < RateHelper::BASIS
            || [] !== array_filter($ledger->getSetting('vat_admission_rates', []), fn ($rate) => (int) $rate < RateHelper::BASIS);
    }
}
