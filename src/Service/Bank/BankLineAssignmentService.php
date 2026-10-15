<?php

namespace Wexample\SymfonyAccounting\Service\Bank;

use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\BankAccountRepository;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;

/**
 * Which organization a line of a shared account belongs to: the ledger whose
 * share holds it. Pointing a line to a ledger moves it into that ledger's
 * share of the account, created on first use; the line stays one line.
 *
 * A line already explained — allocated, or one half of a transfer — stays
 * where it was explained: undo that first.
 */
class BankLineAssignmentService
{
    public function __construct(
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly ChartService $chartService,
    ) {
    }

    /**
     * The ledgers that hold a share of the line's account, the owner's first.
     *
     * @return list<Ledger>
     */
    public function getLedgers(BankTransaction $line): array
    {
        return array_map(
            static fn ($share) => $share->getLedger(),
            $this->bankAccountRepository->findShares($line->getBankAccount())
        );
    }

    /**
     * Persisted, not flushed.
     */
    public function assign(
        BankTransaction $line,
        Ledger $ledger
    ): BankTransaction {
        if ($line->getLedger() === $ledger) {
            return $line;
        }

        if ($line->isTransfer()) {
            throw new AccountingException('This line is half of a transfer: unlink it first.');
        }

        foreach ($line->getAllocations() as $allocation) {
            if (AllocationStatus::Excluded !== $allocation->getStatus()) {
                throw new AccountingException('This line is already explained in its books: remove its allocations first.');
            }
        }

        if (null === $line->getBankAccount()->getIban()) {
            throw new AccountingException('An account without IBAN cannot be shared.');
        }

        $line->setBankAccount($this->bankAccountRepository->findOrCreateShare(
            $line->getBankAccount(),
            $ledger,
            $this->accountNumber($ledger)
        ));

        return $line;
    }

    /**
     * The owner's share of the line's account: the line goes back to nobody.
     */
    public function release(BankTransaction $line): BankTransaction
    {
        return $this->assign($line, $this->bankAccountRepository->findShares($line->getBankAccount())[0]->getLedger());
    }

    /**
     * A ledger without chart (a person's) books nothing: its share only holds lines.
     */
    private function accountNumber(Ledger $ledger): string
    {
        try {
            return $this->chartService->getRoleNumber($ledger, AccountRole::Bank);
        } catch (AccountingException) {
            return '512';
        }
    }
}
