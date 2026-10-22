<?php

namespace Wexample\SymfonyAccounting\Service\Invoice;

use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Event\InvoiceStatusChangedEvent;
use Wexample\SymfonyAccounting\Exception\InvoiceTransitionException;

/**
 * Every status change of a document, guarded by one transition map.
 *
 * Emission (→ emitted) belongs to InvoiceEmissionService and payment statuses
 * (→ partially_paid, paid) to the payment updater; they pass `$internal` to reach
 * statuses no user action may set directly.
 */
class InvoiceWorkflow
{
    private const array INTERNAL = [
        InvoiceStatus::Emitted,
        InvoiceStatus::PartiallyPaid,
        InvoiceStatus::Paid,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return list<InvoiceStatus>
     */
    public function getAllowedTransitions(Invoice $invoice): array
    {
        $quotation = InvoiceType::Quotation === $invoice->getType();

        return match ($invoice->getStatus()) {
            InvoiceStatus::Draft => [InvoiceStatus::Waiting, InvoiceStatus::Approved, InvoiceStatus::Emitted, InvoiceStatus::Canceled, InvoiceStatus::Model],
            InvoiceStatus::Waiting => [InvoiceStatus::Draft, InvoiceStatus::Approved, InvoiceStatus::Emitted, InvoiceStatus::Canceled],
            InvoiceStatus::Approved => [InvoiceStatus::Draft, InvoiceStatus::Emitted, InvoiceStatus::Canceled],
            InvoiceStatus::Emitted => $quotation
                ? [InvoiceStatus::Accepted, InvoiceStatus::Refused, InvoiceStatus::Canceled]
                : [InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid, InvoiceStatus::Canceled],
            InvoiceStatus::PartiallyPaid => [InvoiceStatus::Paid, InvoiceStatus::Emitted],
            InvoiceStatus::Paid => [InvoiceStatus::PartiallyPaid, InvoiceStatus::Emitted],
            InvoiceStatus::Accepted => [InvoiceStatus::Refused],
            InvoiceStatus::Refused => [InvoiceStatus::Accepted],
            InvoiceStatus::Model => [InvoiceStatus::Draft, InvoiceStatus::Canceled],
            InvoiceStatus::Canceled => [],
        };
    }

    public function can(
        Invoice $invoice,
        InvoiceStatus $status
    ): bool {
        return in_array($status, $this->getAllowedTransitions($invoice), true);
    }

    /**
     * Same status: no-op, false. Forbidden: exception.
     */
    public function transition(
        Invoice $invoice,
        InvoiceStatus $status,
        bool $internal = false,
        bool $flush = true
    ): bool {
        $previous = $invoice->getStatus();

        if ($previous === $status) {
            return false;
        }

        if (! $this->can($invoice, $status) || (! $internal && in_array($status, self::INTERNAL, true))) {
            throw new InvoiceTransitionException($invoice, $status);
        }

        $invoice->setStatus($status);

        if ($flush) {
            $this->entityManager->flush();
        }

        $this->eventDispatcher->dispatch(new InvoiceStatusChangedEvent($invoice, $previous));

        return true;
    }

    public function submit(Invoice $invoice): bool
    {
        return $this->transition($invoice, InvoiceStatus::Waiting);
    }

    public function approve(Invoice $invoice): bool
    {
        return $this->transition($invoice, InvoiceStatus::Approved);
    }

    public function accept(Invoice $invoice): bool
    {
        return $this->transition($invoice, InvoiceStatus::Accepted);
    }

    public function refuse(Invoice $invoice): bool
    {
        return $this->transition($invoice, InvoiceStatus::Refused);
    }
}
