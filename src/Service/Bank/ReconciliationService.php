<?php

namespace Wexample\SymfonyAccounting\Service\Bank;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\StatementCheck;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Repository\BankStatementRepository;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyAccounting\Repository\EntryLineRepository;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;

/**
 * Do the imported lines explain the balances the bank states, and do the books
 * agree with the bank?
 */
class ReconciliationService
{
    public function __construct(
        private readonly BankStatementRepository $statementRepository,
        private readonly BankTransactionRepository $transactionRepository,
        private readonly EntryLineRepository $lineRepository,
        private readonly ChartService $chartService,
    ) {
    }

    /**
     * The bank balance at the end of a day: the last stated balance on or before
     * it, plus the lines since. Null when no balance was ever stated.
     */
    public function getBalanceAt(
        BankAccount $bankAccount,
        DateTimeImmutable $date
    ): ?int {
        $statement = $this->statementRepository->findLastOnOrBefore($bankAccount, $date);

        if (! $statement) {
            return null;
        }

        return $statement->getBalance()
            + $this->transactionRepository->sumBetween($bankAccount, $statement->getDate(), $date);
    }

    /**
     * Each stated balance checked against the previous one plus the lines in between.
     * The first one is the starting point and always matches.
     *
     * @return list<StatementCheck>
     */
    public function checkStatements(BankAccount $bankAccount): array
    {
        $checks = [];
        $previous = null;

        foreach ($this->statementRepository->findByBankAccount($bankAccount) as $statement) {
            $computed = null === $previous
                ? $statement->getBalance()
                : $previous->getBalance() + $this->transactionRepository->sumBetween($bankAccount, $previous->getDate(), $statement->getDate());

            $checks[] = new StatementCheck($statement, $computed);
            $previous = $statement;
        }

        return $checks;
    }

    /**
     * The balance of the bank account in the books (debit − credit) at a date.
     */
    public function getBookBalanceAt(
        BankAccount $bankAccount,
        DateTimeImmutable $date
    ): int {
        $account = $this->chartService->findAccount($bankAccount->getLedger(), $bankAccount->getAccountNumber());

        if (! $account) {
            return 0;
        }

        $balance = 0;
        foreach ($this->lineRepository->findForAccount($bankAccount->getLedger(), $account, to: $date) as $line) {
            $balance += $line->getBalance();
        }

        return $balance;
    }
}
