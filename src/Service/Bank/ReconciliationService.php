<?php

namespace Wexample\SymfonyAccounting\Service\Bank;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\StatementCheck;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Repository\BankAccountRepository;
use Wexample\SymfonyAccounting\Repository\BankStatementRepository;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyAccounting\Repository\EntryLineRepository;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;

/**
 * Do the imported lines explain the balances the bank states, and do the books
 * agree with the bank?
 *
 * The bank states the balance of the whole account: an account shared between
 * ledgers is checked with the lines of all its shares, against the balances
 * its shares were given.
 */
class ReconciliationService
{
    public function __construct(
        private readonly BankStatementRepository $statementRepository,
        private readonly BankTransactionRepository $transactionRepository,
        private readonly EntryLineRepository $lineRepository,
        private readonly ChartService $chartService,
        private readonly BankAccountRepository $bankAccountRepository,
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
        $shares = $this->bankAccountRepository->findShares($bankAccount);
        $statement = null;

        foreach ($shares as $share) {
            $candidate = $this->statementRepository->findLastOnOrBefore($share, $date);

            if ($candidate && (! $statement || $candidate->getDate() > $statement->getDate())) {
                $statement = $candidate;
            }
        }

        if (! $statement) {
            return null;
        }

        return $statement->getBalance()
            + $this->transactionRepository->sumBetween($shares, $statement->getDate(), $date);
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
        $shares = $this->bankAccountRepository->findShares($bankAccount);
        $statements = array_merge(...array_map(fn (BankAccount $share) => $this->statementRepository->findByBankAccount($share), $shares));
        usort($statements, static fn ($a, $b) => $a->getDate() <=> $b->getDate());

        foreach ($statements as $statement) {
            $computed = null === $previous
                ? $statement->getBalance()
                : $previous->getBalance() + $this->transactionRepository->sumBetween($shares, $previous->getDate(), $statement->getDate());

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
