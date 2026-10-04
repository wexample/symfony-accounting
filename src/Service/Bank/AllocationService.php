<?php

namespace Wexample\SymfonyAccounting\Service\Bank;

use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Wexample\SymfonyAccounting\Entity\Allocation;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyAccounting\Event\AllocationChangedEvent;
use Wexample\SymfonyAccounting\Event\TransferLinkedEvent;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\AllocationRepository;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;

/**
 * Explaining bank lines: what each one pays, or which other line it was moved to.
 *
 * Removing an allocation excludes it rather than deleting it, so the pair is
 * never proposed again; a validated allocation is booked (see
 * AllocationAccountingService), and unbooked when excluded.
 */
class AllocationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AllocationRepository $allocationRepository,
        private readonly BankTransactionRepository $transactionRepository,
    ) {
    }

    /**
     * @param int|null $amount Positive part of the line paying the document;
     *                         what is left of both by default.
     */
    public function allocate(
        BankTransaction $transaction,
        Invoice $invoice,
        ?int $amount = null,
        AllocationStatus $status = AllocationStatus::Validated,
        string $origin = 'manual',
    ): Allocation {
        if ($invoice->getLedger() !== $transaction->getLedger()) {
            throw new AccountingException('The document belongs to another ledger.');
        }

        if (! $invoice->getStatus()->isEmitted()) {
            throw new AccountingException('Only an emitted document can be paid.');
        }

        $sign = $transaction->getAmount() <=> 0;

        if ($sign !== $invoice->getPaymentSign()) {
            throw new AccountingException($invoice->isSale()
                ? 'A sale is paid by money coming in, a sale credit note by money going out.'
                : 'A purchase is paid by money going out, a purchase credit note by money coming in.');
        }

        $amount ??= min(abs($transaction->getUnallocatedAmount()), max(0, $invoice->calcRemainingAmount()));

        if ($amount <= 0) {
            throw new AccountingException('Nothing is left to allocate between this line and this document.');
        }

        return $this->create(
            (new Allocation())->setInvoice($invoice)->setAmount($sign * $amount),
            $transaction,
            $status,
            $origin
        );
    }

    /**
     * Books (part of) a line straight on an account: fees, taxes, an owner's withdrawal.
     *
     * @param int $vatRate VAT included in the amount, in basis points.
     */
    public function allocateToAccount(
        BankTransaction $transaction,
        string $accountNumber,
        ?int $amount = null,
        int $vatRate = 0,
        ?Party $party = null,
        ?string $label = null,
        AllocationStatus $status = AllocationStatus::Validated,
        string $origin = 'manual',
    ): Allocation {
        $sign = $transaction->getAmount() <=> 0;
        $amount ??= abs($transaction->getUnallocatedAmount());

        return $this->create(
            (new Allocation())
                ->setAccountNumber($accountNumber)
                ->setVatRate($vatRate)
                ->setParty($party)
                ->setLabel($label)
                ->setAmount($sign * $amount),
            $transaction,
            $status,
            $origin
        );
    }

    public function validate(Allocation $allocation): void
    {
        $this->changeStatus($allocation, AllocationStatus::Validated);
    }

    /**
     * Refuses or removes an allocation; it is kept as excluded.
     */
    public function exclude(Allocation $allocation): void
    {
        $this->changeStatus($allocation, AllocationStatus::Excluded);
    }

    /**
     * Links two lines of opposite amounts on two accounts of the ledger as one
     * internal transfer. The negative line owns the link.
     */
    public function linkTransfer(
        BankTransaction $a,
        BankTransaction $b
    ): void {
        if ($a->getAmount() !== -$b->getAmount()) {
            throw new AccountingException('A transfer links two lines of opposite amounts.');
        }

        if ($a->getBankAccount() === $b->getBankAccount() || $a->getLedger() !== $b->getLedger()) {
            throw new AccountingException('A transfer links two different accounts of the same ledger.');
        }

        foreach ([$a, $b] as $line) {
            if ($line->isTransfer() || $this->transactionRepository->isTransferTarget($line) || [] !== $line->getActiveAllocations()) {
                throw new AccountingException(sprintf('Line "%s" is already explained.', $line->getLabel()));
            }
        }

        [$outgoing, $incoming] = $a->getAmount() < 0 ? [$a, $b] : [$b, $a];
        $outgoing->setTransferPeer($incoming);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new TransferLinkedEvent($outgoing, $incoming));
    }

    public function unlinkTransfer(BankTransaction $line): void
    {
        $outgoing = $line->isTransfer() ? $line : $this->transactionRepository->findTransferSource($line);

        if (! $outgoing) {
            return;
        }

        $incoming = $outgoing->getTransferPeer();
        $outgoing->setTransferPeer(null);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new TransferLinkedEvent($outgoing, $incoming, linked: false));
    }

    private function create(
        Allocation $allocation,
        BankTransaction $transaction,
        AllocationStatus $status,
        string $origin
    ): Allocation {
        if ($transaction->isTransfer() || $this->transactionRepository->isTransferTarget($transaction)) {
            throw new AccountingException('This line is an internal transfer.');
        }

        $left = $transaction->getUnallocatedAmount();

        if (abs($allocation->getAmount()) > abs($left)) {
            throw new AccountingException(sprintf(
                'Only %d is left to allocate on this line, %d asked.',
                abs($left),
                abs($allocation->getAmount())
            ));
        }

        $transaction->addAllocation($allocation);
        $allocation->setStatus($status)->setOrigin($origin);
        $this->entityManager->persist($allocation);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new AllocationChangedEvent($allocation, null));

        return $allocation;
    }

    private function changeStatus(
        Allocation $allocation,
        AllocationStatus $status
    ): void {
        $previous = $allocation->getStatus();

        if ($previous === $status) {
            return;
        }

        if (AllocationStatus::Excluded === $previous) {
            throw new AccountingException('An excluded allocation stays excluded; allocate again.');
        }

        $allocation->setStatus($status);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new AllocationChangedEvent($allocation, $previous));
    }
}
