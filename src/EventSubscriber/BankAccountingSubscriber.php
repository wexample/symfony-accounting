<?php

namespace Wexample\SymfonyAccounting\EventSubscriber;

use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyAccounting\Enum\InvoiceRelationType;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Event\AllocationChangedEvent;
use Wexample\SymfonyAccounting\Event\InvoiceEmittedEvent;
use Wexample\SymfonyAccounting\Event\TransferLinkedEvent;
use Wexample\SymfonyAccounting\Repository\InvoiceRelationRepository;
use Wexample\SymfonyAccounting\Service\Bank\AllocationAccountingService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceWorkflow;

/**
 * Keeps the books and the documents in step with the bank:
 * validated allocations and transfers are booked, removed ones reversed, and
 * each paid document moves between emitted, partially paid and paid — a credit
 * note emitted counting as what it takes back.
 *
 * A ledger can turn booking off with the setting `auto_post_bank: false`.
 */
class BankAccountingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AllocationAccountingService $accountingService,
        private readonly InvoiceWorkflow $workflow,
        private readonly InvoiceRelationRepository $relationRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AllocationChangedEvent::class => 'onAllocationChanged',
            TransferLinkedEvent::class => 'onTransferLinked',
            InvoiceEmittedEvent::class => 'onInvoiceEmitted',
        ];
    }

    public function onInvoiceEmitted(InvoiceEmittedEvent $event): void
    {
        if (! $event->invoice->isCreditNote()) {
            return;
        }

        foreach ($this->relationRepository->findBy(['target' => $event->invoice, 'type' => InvoiceRelationType::Credit]) as $relation) {
            $this->updatePaymentStatus($relation->getSource());
        }
    }

    public function onAllocationChanged(AllocationChangedEvent $event): void
    {
        $allocation = $event->allocation;
        $ledger = $allocation->getTransaction()->getLedger();

        if ($ledger->getSetting('auto_post_bank', true)) {
            if (AllocationStatus::Validated === $allocation->getStatus()) {
                $this->accountingService->book($allocation);
            } elseif (AllocationStatus::Validated === $event->previousStatus) {
                $this->accountingService->unbook($allocation);
            }
        }

        if ($allocation->getInvoice()) {
            $this->updatePaymentStatus($allocation->getInvoice());
        }
    }

    public function onTransferLinked(TransferLinkedEvent $event): void
    {
        if (! $event->outgoing->getLedger()->getSetting('auto_post_bank', true)) {
            return;
        }

        $event->linked
            ? $this->accountingService->bookTransfer($event->outgoing)
            : $this->accountingService->unbookTransfer($event->outgoing, $event->incoming);
    }

    public function updatePaymentStatus(Invoice $invoice): void
    {
        $status = $invoice->getStatus();

        if (! in_array($status, [InvoiceStatus::Emitted, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true)) {
            return;
        }

        $paid = $invoice->calcPaidAmount();
        $expected = $invoice->calcAmountExpected();
        $target = match (true) {
            $paid >= $expected && ($expected > 0 || $invoice->calcCreditedAmount() > 0) => InvoiceStatus::Paid,
            $paid > 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Emitted,
        };

        if (InvoiceStatus::Paid === $target) {
            $last = null;
            foreach ($invoice->getAllocations() as $allocation) {
                if ($allocation->getStatus()->isActive()) {
                    $date = $allocation->getTransaction()->getDate();
                    $last = null === $last || $date > $last ? $date : $last;
                }
            }
            $invoice->setDatePaid($last ?? new DateTimeImmutable());
        } else {
            $invoice->setDatePaid(null);
        }

        $this->workflow->transition($invoice, $target, internal: true);
    }
}
