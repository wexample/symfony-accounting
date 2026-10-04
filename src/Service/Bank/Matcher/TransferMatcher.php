<?php

namespace Wexample\SymfonyAccounting\Service\Bank\Matcher;

use Wexample\SymfonyAccounting\Class\MatchProposal;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Interface\TransactionMatcherInterface;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;

/**
 * Money leaving one account of the ledger and reaching another within a few days
 * for the same amount: an internal transfer, or a provider payout to the bank.
 */
class TransferMatcher implements TransactionMatcherInterface
{
    public const int WINDOW_DAYS = 5;

    public function __construct(
        private readonly BankTransactionRepository $transactionRepository,
    ) {
    }

    public function getName(): string
    {
        return 'transfer';
    }

    public function getPriority(): int
    {
        return 80;
    }

    public function propose(BankTransaction $transaction): array
    {
        if ([] !== $transaction->getActiveAllocations()) {
            return [];
        }

        $candidates = array_values(array_filter(
            $this->transactionRepository->findTransferCandidates($transaction, self::WINDOW_DAYS),
            fn (BankTransaction $candidate) => [] === $candidate->getActiveAllocations()
                && ! $this->transactionRepository->isTransferTarget($candidate)
        ));

        if ([] === $candidates) {
            return [];
        }

        usort($candidates, fn (BankTransaction $a, BankTransaction $b) => abs($a->getDate()->getTimestamp() - $transaction->getDate()->getTimestamp())
            <=> abs($b->getDate()->getTimestamp() - $transaction->getDate()->getTimestamp()));

        return [new MatchProposal($this->getName(), 1 === count($candidates) ? 85 : 50, transferPeer: $candidates[0])];
    }
}
