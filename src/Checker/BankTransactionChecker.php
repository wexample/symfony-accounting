<?php

namespace Wexample\SymfonyAccounting\Checker;

use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyCheck\Class\Finding;
use Wexample\SymfonyCheck\Interface\CheckerInterface;

/**
 * Network's warnings on bank lines: unexplained, over-allocated, transfers that
 * do not match, payments waiting for validation.
 */
class BankTransactionChecker implements CheckerInterface
{
    public const string DOMAIN = 'accounting_check';

    public function __construct(
        private readonly BankTransactionRepository $transactionRepository,
    ) {
    }

    public function supports(object $subject): bool
    {
        return $subject instanceof BankTransaction;
    }

    /**
     * @param BankTransaction $subject
     */
    public function check(object $subject): iterable
    {
        $transaction = $subject;

        if ($peer = $transaction->getTransferPeer()) {
            if ($peer->getAmount() !== -$transaction->getAmount()) {
                yield Finding::error('bank.transfer_amounts_mismatch', [], self::DOMAIN);
            }

            return;
        }

        if ($this->transactionRepository->isTransferTarget($transaction)) {
            return;
        }

        $allocated = abs($transaction->getAllocatedAmount());

        if ($allocated > abs($transaction->getAmount())) {
            yield Finding::error('bank.allocated_exceeded', ['excess' => $allocated - abs($transaction->getAmount())], self::DOMAIN);
        } elseif (0 === $allocated) {
            yield Finding::warning('bank.unexplained', [], self::DOMAIN);
        } elseif ($allocated < abs($transaction->getAmount())) {
            yield Finding::warning('bank.partially_explained', ['remaining' => abs($transaction->getAmount()) - $allocated], self::DOMAIN);
        }

        foreach ($transaction->getAllocations() as $allocation) {
            if (AllocationStatus::Pending === $allocation->getStatus()) {
                yield Finding::info('bank.pending_allocation', ['origin' => $allocation->getOrigin()], self::DOMAIN);
            }
        }
    }
}
