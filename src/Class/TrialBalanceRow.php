<?php

namespace Wexample\SymfonyAccounting\Class;

final readonly class TrialBalanceRow
{
    public function __construct(
        public string $number,
        public string $label,
        public int $debit,
        public int $credit,
    ) {
    }

    /**
     * Debit minus credit.
     */
    public function getBalance(): int
    {
        return $this->debit - $this->credit;
    }

    public function getDebitBalance(): int
    {
        return max(0, $this->getBalance());
    }

    public function getCreditBalance(): int
    {
        return max(0, -$this->getBalance());
    }
}
