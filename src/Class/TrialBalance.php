<?php

namespace Wexample\SymfonyAccounting\Class;

/**
 * Debit and credit totals of every account over a period.
 */
final readonly class TrialBalance
{
    /**
     * @param array<string, TrialBalanceRow> $rows Keyed by account number, sorted.
     */
    public function __construct(
        public array $rows,
    ) {
    }

    public function getTotalDebit(): int
    {
        return array_sum(array_map(fn (TrialBalanceRow $row) => $row->debit, $this->rows));
    }

    public function getTotalCredit(): int
    {
        return array_sum(array_map(fn (TrialBalanceRow $row) => $row->credit, $this->rows));
    }

    public function isBalanced(): bool
    {
        return $this->getTotalDebit() === $this->getTotalCredit();
    }

    /**
     * Net balance (debit − credit) of the accounts starting with a prefix.
     */
    public function getBalance(string $prefix = ''): int
    {
        $sum = 0;

        foreach ($this->rows as $number => $row) {
            if (str_starts_with((string) $number, $prefix)) {
                $sum += $row->getBalance();
            }
        }

        return $sum;
    }

    /**
     * @return array<int, array{debit: int, credit: int}> Totals per account class.
     */
    public function getClassTotals(): array
    {
        $totals = [];

        foreach ($this->rows as $number => $row) {
            $class = (int) ((string) $number)[0];
            $totals[$class] ??= ['debit' => 0, 'credit' => 0];
            $totals[$class]['debit'] += $row->debit;
            $totals[$class]['credit'] += $row->credit;
        }

        ksort($totals);

        return $totals;
    }

    /**
     * @return array<string, int> Account number → net balance.
     */
    public function getBalances(): array
    {
        return array_map(fn (TrialBalanceRow $row) => $row->getBalance(), $this->rows);
    }
}
