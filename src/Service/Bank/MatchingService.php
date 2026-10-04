<?php

namespace Wexample\SymfonyAccounting\Service\Bank;

use Wexample\SymfonyAccounting\Class\MatchProposal;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Interface\TransactionMatcherInterface;
use Wexample\SymfonyAccounting\Repository\AllocationRepository;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;

/**
 * Runs the matchers over unexplained bank lines. A confident proposal becomes a
 * pending allocation (validated at once when the matcher is sure), never one a
 * person excluded before. Transfers are linked when the matcher is confident.
 */
class MatchingService
{
    public const int DEFAULT_THRESHOLD = 60;

    /** @var list<TransactionMatcherInterface> */
    private array $matchers;

    /**
     * @param iterable<TransactionMatcherInterface> $matchers
     */
    public function __construct(
        iterable $matchers,
        private readonly AllocationService $allocationService,
        private readonly AllocationRepository $allocationRepository,
        private readonly BankTransactionRepository $transactionRepository,
    ) {
        $this->matchers = iterator_to_array($matchers, false);
        usort($this->matchers, fn ($a, $b) => $b->getPriority() <=> $a->getPriority());
    }

    /**
     * @return int The number of lines explained.
     */
    public function run(
        Ledger $ledger,
        ?BankAccount $bankAccount = null,
        int $threshold = self::DEFAULT_THRESHOLD
    ): int {
        $count = 0;

        foreach ($this->transactionRepository->findUnsettled($ledger, $bankAccount) as $transaction) {
            if ($transaction->isSettled() || $transaction->isTransfer() || $this->transactionRepository->isTransferTarget($transaction)) {
                continue;
            }

            $count += (int) $this->matchTransaction($transaction, $threshold);
        }

        return $count;
    }

    /**
     * Every proposal for one line, best first, without the excluded ones.
     *
     * @return list<MatchProposal>
     */
    public function propose(BankTransaction $transaction): array
    {
        $proposals = [];

        foreach ($this->matchers as $matcher) {
            foreach ($matcher->propose($transaction) as $proposal) {
                if (! $this->isExcluded($transaction, $proposal)) {
                    $proposals[] = $proposal;
                }
            }
        }

        usort($proposals, fn (MatchProposal $a, MatchProposal $b) => $b->confidence <=> $a->confidence);

        return $proposals;
    }

    public function matchTransaction(
        BankTransaction $transaction,
        int $threshold = self::DEFAULT_THRESHOLD
    ): bool {
        foreach ($this->propose($transaction) as $proposal) {
            if ($proposal->confidence < $threshold) {
                return false;
            }

            try {
                $this->apply($transaction, $proposal);

                return true;
            } catch (AccountingException) {
                // The proposal no longer fits (amount left, already linked): try the next one.
            }
        }

        return false;
    }

    public function apply(
        BankTransaction $transaction,
        MatchProposal $proposal
    ): void {
        $status = $proposal->autoValidate ? AllocationStatus::Validated : AllocationStatus::Pending;

        if ($proposal->transferPeer) {
            $this->allocationService->linkTransfer($transaction, $proposal->transferPeer);
        } elseif ($proposal->invoice) {
            $this->allocationService->allocate($transaction, $proposal->invoice, $proposal->amount, $status, $proposal->matcher);
        } elseif ($proposal->accountNumber) {
            $this->allocationService->allocateToAccount(
                $transaction,
                $proposal->accountNumber,
                $proposal->amount,
                $proposal->vatRate,
                $proposal->party,
                $proposal->label,
                $status,
                $proposal->matcher,
            );
        }
    }

    private function isExcluded(
        BankTransaction $transaction,
        MatchProposal $proposal
    ): bool {
        if ($proposal->transferPeer) {
            return false;
        }

        return $this->allocationRepository->isExcluded($transaction, $proposal->invoice, $proposal->accountNumber);
    }
}
