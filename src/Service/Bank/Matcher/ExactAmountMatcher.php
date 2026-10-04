<?php

namespace Wexample\SymfonyAccounting\Service\Bank\Matcher;

use Wexample\SymfonyAccounting\Class\MatchProposal;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Interface\TransactionMatcherInterface;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;

/**
 * Network's rule, improved: a document waiting for exactly the line's amount
 * (what is left to pay, after write-offs, not its gross total), in the right
 * direction, dated within a year before the line and not after it by more than
 * a week. The newest comes first, but every candidate is proposed. A line from
 * the party's known IBAN is more certain. The line's date is never changed.
 */
class ExactAmountMatcher implements TransactionMatcherInterface
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
    ) {
    }

    public function getName(): string
    {
        return 'exact_amount';
    }

    public function getPriority(): int
    {
        return 50;
    }

    public function propose(BankTransaction $transaction): array
    {
        $date = $transaction->getDate();
        $amount = abs($transaction->getUnallocatedAmount());
        $proposals = [];

        $candidates = $this->invoiceRepository->findAwaitingPayment(
            $transaction->getLedger(),
            $date->modify('-12 months'),
            $date->modify('+7 days')
        );

        foreach ($candidates as $invoice) {
            if (($transaction->getAmount() <=> 0) !== $invoice->getPaymentSign() || $invoice->calcRemainingAmount() !== $amount) {
                continue;
            }

            $sameIban = $transaction->getCounterpartyIban()
                && $transaction->getCounterpartyIban() === $invoice->getParty()?->getIban();

            $proposals[] = new MatchProposal($this->getName(), $sameIban ? 90 : 70, invoice: $invoice);
        }

        // Several candidates: none is certain enough to be applied alone.
        if (count($proposals) > 1) {
            $proposals = array_map(
                fn (MatchProposal $p) => new MatchProposal($p->matcher, min($p->confidence, 40), invoice: $p->invoice),
                $proposals
            );
        }

        return $proposals;
    }
}
