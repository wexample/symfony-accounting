<?php

namespace Wexample\SymfonyAccounting\Service\Check;

use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyCheck\Interface\SubjectProviderInterface;

/**
 * `check:run bank`: every bank line not explained yet, of every ledger.
 */
class UnsettledTransactionSubjectProvider implements SubjectProviderInterface
{
    public function __construct(
        private readonly LedgerRepository $ledgerRepository,
        private readonly BankTransactionRepository $transactionRepository,
    ) {
    }

    public function getKey(): string
    {
        return 'bank';
    }

    public function getSubjects(): iterable
    {
        foreach ($this->ledgerRepository->findAll() as $ledger) {
            yield from $this->transactionRepository->findUnsettled($ledger);
        }
    }
}
