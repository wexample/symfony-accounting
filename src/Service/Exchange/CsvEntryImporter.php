<?php

namespace Wexample\SymfonyAccounting\Service\Exchange;

use DateTimeImmutable;
use League\Csv\Reader;
use Wexample\SymfonyAccounting\Class\ImportedLine;
use Wexample\SymfonyAccounting\Interface\EntryImporterInterface;
use Wexample\SymfonyMoney\Helper\MoneyHelper;

/**
 * Reads entries from a CSV with the columns of CsvEntryExporter (only journal,
 * number, date, account, label and debit/credit are required). Other software's
 * CSV can be mapped with the `columns` option: our name → their header.
 */
class CsvEntryImporter implements EntryImporterInterface
{
    public function getKey(): string
    {
        return 'csv_entries';
    }

    public function getLabel(): string
    {
        return 'Entries (CSV)';
    }

    public function supports(string $content): bool
    {
        $header = strtolower(strtok($content, "\n") ?: '');

        return str_contains($header, 'journal') && str_contains($header, 'account') && str_contains($header, 'debit');
    }

    public function read(
        string $content,
        array $options = []
    ): iterable {
        $csv = Reader::fromString($content);
        $csv->setDelimiter($options['delimiter'] ?? ';');
        $csv->setHeaderOffset(0);
        $columns = ($options['columns'] ?? []) + array_combine(CsvEntryExporter::COLUMNS, CsvEntryExporter::COLUMNS);
        $currency = $options['currency'] ?? 'EUR';
        $dateFormat = $options['date_format'] ?? 'Y-m-d';

        foreach ($csv->getRecords() as $record) {
            $get = fn (string $name): ?string => isset($record[$columns[$name]]) && '' !== trim($record[$columns[$name]])
                ? trim($record[$columns[$name]])
                : null;
            $date = fn (?string $value) => null === $value ? null : DateTimeImmutable::createFromFormat('!'.$dateFormat, $value);

            yield new ImportedLine(
                journalCode: (string) $get('journal'),
                entryNumber: (string) $get('number'),
                date: $date($get('date')),
                accountNumber: (string) $get('account'),
                label: (string) ($get('label') ?? ''),
                debit: MoneyHelper::fromDecimal($get('debit') ?? '0', $currency),
                credit: MoneyHelper::fromDecimal($get('credit') ?? '0', $currency),
                journalLabel: $get('journal_label'),
                accountLabel: $get('account_label'),
                auxiliaryCode: $get('auxiliary'),
                auxiliaryLabel: $get('auxiliary_label'),
                pieceReference: $get('piece'),
                pieceDate: $date($get('piece_date')),
                letter: $get('letter'),
                dateLettered: $date($get('date_lettered')),
                dateValidated: $date($get('date_validated')),
            );
        }
    }
}
