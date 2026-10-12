<?php

namespace Wexample\SymfonyAccounting\Class;

/**
 * Where a ledger's VAT stands over a year, period by period: what is owed,
 * what was paid ahead, what is left.
 */
final class VatPosition
{
    /**
     * @param list<VatPositionPeriod> $periods
     * @param int $previousCredit VAT credit carried into the year.
     */
    public function __construct(
        public readonly int $year,
        public readonly array $periods,
        public readonly int $previousCredit,
    ) {
    }

    public function getNet(): int
    {
        return $this->sum(fn (VatPositionPeriod $period) => $period->getNet());
    }

    public function getDepositsPaid(): int
    {
        return $this->sum(fn (VatPositionPeriod $period) => $period->depositsPaid);
    }

    public function getDepositsExpected(): int
    {
        return $this->sum(fn (VatPositionPeriod $period) => $period->depositsExpected);
    }

    /**
     * What is left to pay for the year (negative: a credit).
     */
    public function getBalance(): int
    {
        return $this->getNet() - $this->getDepositsPaid() - $this->previousCredit;
    }

    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'periods' => array_map(fn (VatPositionPeriod $period) => $period->toArray(), $this->periods),
            'collected' => $this->sum(fn (VatPositionPeriod $period) => $period->collected),
            'deductible' => $this->sum(fn (VatPositionPeriod $period) => $period->deductible),
            'deductibleAssets' => $this->sum(fn (VatPositionPeriod $period) => $period->deductibleAssets),
            'net' => $this->getNet(),
            'previousCredit' => $this->previousCredit,
            'depositsPaid' => $this->getDepositsPaid(),
            'depositsExpected' => $this->getDepositsExpected(),
            'balance' => $this->getBalance(),
        ];
    }

    private function sum(callable $amount): int
    {
        return array_sum(array_map($amount, $this->periods));
    }
}
