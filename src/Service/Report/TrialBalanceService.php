<?php

namespace Wexample\SymfonyAccounting\Service\Report;

use DateTimeInterface;
use Wexample\SymfonyAccounting\Class\TrialBalance;
use Wexample\SymfonyAccounting\Class\TrialBalanceRow;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Repository\AccountRepository;
use Wexample\SymfonyAccounting\Repository\EntryLineRepository;

/**
 * The trial balance (balance générale): totals per account, computed on demand.
 */
class TrialBalanceService
{
    public function __construct(
        private readonly EntryLineRepository $lineRepository,
        private readonly AccountRepository $accountRepository,
    ) {
    }

    public function build(
        Ledger $ledger,
        ?FiscalYear $fiscalYear = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
    ): TrialBalance {
        $accounts = $this->accountRepository->findIndexedByNumber($ledger);
        $rows = [];

        foreach ($this->lineRepository->sumByAccount($ledger, $from, $to, $fiscalYear) as $number => $sums) {
            $rows[$number] = new TrialBalanceRow(
                (string) $number,
                isset($accounts[$number]) ? $accounts[$number]->getLabel() : '',
                $sums['debit'],
                $sums['credit'],
            );
        }

        return new TrialBalance($rows);
    }
}
