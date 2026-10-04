<?php

namespace Wexample\SymfonyAccounting\Service\Invoice;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Wexample\SymfonyAccounting\Class\DunningNotice;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Event\InvoiceReminderRecordedEvent;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;

/**
 * Payment reminders by level: a sale overdue by at least the days of a level
 * gets that level's reminder, once. Levels come from the ledger setting
 * `dunning_levels` (days late; [7, 30, 60] by default). Reminders sent are kept
 * in the document's metadata, so the history shows on it (#309).
 */
class DunningService
{
    public const array DEFAULT_LEVELS = [7, 30, 60];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly InvoiceRepository $invoiceRepository,
    ) {
    }

    /**
     * @return list<DunningNotice>
     */
    public function findDue(
        Ledger $ledger,
        ?DateTimeImmutable $today = null
    ): array {
        $today = ($today ?? new DateTimeImmutable())->setTime(0, 0);
        $levels = $ledger->getSetting('dunning_levels', self::DEFAULT_LEVELS);
        $notices = [];

        foreach ($this->invoiceRepository->findAwaitingPayment($ledger) as $invoice) {
            if (! $invoice->isSale() || $invoice->isCreditNote() || $invoice->calcRemainingAmount() <= 0) {
                continue;
            }

            $due = $invoice->calcDateDue();
            if ($due >= $today) {
                continue;
            }

            $daysLate = (int) $due->diff($today)->days;
            $level = 0;

            foreach ($levels as $index => $days) {
                if ($daysLate >= $days) {
                    $level = $index + 1;
                }
            }

            if ($level > $this->getLastLevel($invoice)) {
                $notices[] = new DunningNotice($invoice, $level, $daysLate, $invoice->calcRemainingAmount());
            }
        }

        return $notices;
    }

    public function record(
        DunningNotice $notice,
        ?DateTimeImmutable $date = null
    ): void {
        $invoice = $notice->invoice;
        $metadata = $invoice->getMetadata();
        $metadata['reminders'][] = [
            'level' => $notice->level,
            'date' => ($date ?? new DateTimeImmutable())->format('Y-m-d'),
            'remaining' => $notice->remaining,
        ];
        $invoice->setMetadata($metadata);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new InvoiceReminderRecordedEvent($notice));
    }

    public function getLastLevel(Invoice $invoice): int
    {
        $levels = array_column($invoice->getMetadata()['reminders'] ?? [], 'level');

        return [] === $levels ? 0 : max($levels);
    }
}
