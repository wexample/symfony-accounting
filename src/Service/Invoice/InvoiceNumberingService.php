<?php

namespace Wexample\SymfonyAccounting\Service\Invoice;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceSequence;
use Wexample\SymfonyAccounting\Entity\Ledger;

/**
 * Gap-free numbering, one series per ledger, document type and year.
 *
 * The format comes from the ledger setting `invoice_number_pattern`, with the
 * placeholders {series}, {prefix}, {year}, {number} (zero-padded to
 * `invoice_number_padding`, 4 by default): "BIL-WEE2026-0001" by default.
 */
class InvoiceNumberingService
{
    public const string DEFAULT_PATTERN = '{series}-{prefix}{year}-{number}';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Takes the next number of the document's series. Runs in a transaction with
     * the sequence row locked, so two emissions never get the same number.
     */
    public function next(Invoice $invoice): string
    {
        $ledger = $invoice->getLedger();
        $series = $this->getSeries($invoice);
        $year = (int) $invoice->getDateInvoice()->format('Y');

        $number = $this->entityManager->wrapInTransaction(function () use ($ledger, $series, $year): int {
            $sequence = $this->findSequence($ledger, $series, $year, lock: true);

            if (! $sequence) {
                $sequence = (new InvoiceSequence())->setLedger($ledger)->setSeries($series)->setYear($year);
                $this->entityManager->persist($sequence);
            }

            return $sequence->increment();
        });

        return $this->format($ledger, $series, $year, $number);
    }

    /**
     * The number the document would get now, without taking it.
     */
    public function peek(Invoice $invoice): string
    {
        $series = $this->getSeries($invoice);
        $year = (int) $invoice->getDateInvoice()->format('Y');
        $sequence = $this->findSequence($invoice->getLedger(), $series, $year);

        return $this->format($invoice->getLedger(), $series, $year, ($sequence?->getLastNumber() ?? 0) + 1);
    }

    public function getSeries(Invoice $invoice): string
    {
        $custom = $invoice->getLedger()->getSetting('invoice_series', []);

        return $custom[$invoice->getType()->value] ?? $invoice->getType()->getSeriesCode();
    }

    public function format(
        Ledger $ledger,
        string $series,
        int $year,
        int $number
    ): string {
        return strtr(
            (string) $ledger->getSetting('invoice_number_pattern', self::DEFAULT_PATTERN),
            [
                '{series}' => $series,
                '{prefix}' => (string) $ledger->getInvoicePrefix(),
                '{year}' => (string) $year,
                '{number}' => str_pad((string) $number, (int) $ledger->getSetting('invoice_number_padding', 4), '0', STR_PAD_LEFT),
            ]
        );
    }

    private function findSequence(
        Ledger $ledger,
        string $series,
        int $year,
        bool $lock = false
    ): ?InvoiceSequence {
        $sequence = $this->entityManager->getRepository(InvoiceSequence::class)
            ->findOneBy(['ledger' => $ledger, 'series' => $series, 'year' => $year]);

        if ($sequence && $lock) {
            $this->entityManager->lock($sequence, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($sequence);
        }

        return $sequence;
    }
}
