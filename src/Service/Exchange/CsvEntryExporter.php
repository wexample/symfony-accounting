<?php

namespace Wexample\SymfonyAccounting\Service\Exchange;

use Wexample\SymfonyAccounting\Class\ExportFile;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Interface\LedgerExporterInterface;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyMoney\Helper\MoneyHelper;

/**
 * Every booked line of a fiscal year as CSV, one line per row, in the columns
 * CsvEntryImporter reads back: a portable copy of the books.
 */
class CsvEntryExporter implements LedgerExporterInterface
{
    public const array COLUMNS = [
        'journal', 'journal_label', 'number', 'date', 'account', 'account_label',
        'auxiliary', 'auxiliary_label', 'piece', 'piece_date', 'label',
        'debit', 'credit', 'letter', 'date_lettered', 'date_validated',
    ];

    public function __construct(
        private readonly JournalEntryRepository $entryRepository,
    ) {
    }

    public function getKey(): string
    {
        return 'csv_entries';
    }

    public function getLabel(): string
    {
        return 'Entries (CSV)';
    }

    public function supports(FiscalYear $fiscalYear): bool
    {
        return true;
    }

    public function export(
        FiscalYear $fiscalYear,
        array $options = []
    ): ExportFile {
        $delimiter = $options['delimiter'] ?? ';';
        $currency = $fiscalYear->getLedger()->getCurrencyCode();
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, self::COLUMNS, $delimiter, '"', '');

        foreach ($this->entryRepository->findBooked($fiscalYear) as $entry) {
            foreach ($entry->getLines() as $line) {
                fputcsv($handle, [
                    $entry->getJournal()->getCode(),
                    $entry->getJournal()->getLabel(),
                    $entry->getNumber(),
                    $entry->getDate()->format('Y-m-d'),
                    $line->getAccount()->getNumber(),
                    $line->getAccount()->getLabel(),
                    $line->getAuxiliaryCode(),
                    $line->getParty()?->getName(),
                    $entry->getPieceReference(),
                    $entry->getPieceDate()?->format('Y-m-d'),
                    $line->getLabel(),
                    MoneyHelper::toDecimal($line->getDebit(), $currency),
                    MoneyHelper::toDecimal($line->getCredit(), $currency),
                    $line->getLetter(),
                    $line->getDateLettered()?->format('Y-m-d'),
                    $entry->getDateValidated()?->format('Y-m-d'),
                ], $delimiter, '"', '');
            }
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return new ExportFile('entries-'.$fiscalYear->getLabel().'.csv', $content);
    }
}
