<?php

namespace Wexample\SymfonyAccounting\Service\Bank\Parser;

use Wexample\SymfonyAccounting\Class\ParsedBalance;
use Wexample\SymfonyAccounting\Class\ParsedStatement;
use Wexample\SymfonyAccounting\Class\ParsedTransaction;
use Wexample\SymfonyAccounting\Exception\AccountingException;

/**
 * Any bank's CSV, described by options, so that a new bank needs settings rather
 * than code. Columns are header names (with `header: true`) or 0-based indexes.
 *
 * Options: delimiter (";" by default, "auto" to guess), skip (lines before the
 * data or the header), header (bool), date_column, date_format ("d/m/Y"),
 * label_columns (list, joined), amount_column, or debit_column + credit_column,
 * reference_column, counterparty_column, external_id_column, currency ("EUR"),
 * balance (a stated balance) + balance_date.
 */
class CsvBankStatementParser extends AbstractBankStatementParser
{
    public function getKey(): string
    {
        return 'csv';
    }

    public function getLabel(): string
    {
        return 'CSV';
    }

    public function supports(
        string $content,
        ?string $filename = null
    ): bool {
        // Needs a description of its columns: never chosen automatically.
        return false;
    }

    public function parse(
        string $content,
        array $options = []
    ): ParsedStatement {
        $options += [
            'delimiter' => ';',
            'skip' => 0,
            'header' => true,
            'date_column' => 0,
            'date_format' => 'd/m/Y',
            'label_columns' => [1],
            'amount_column' => 2,
            'debit_column' => null,
            'credit_column' => null,
            'reference_column' => null,
            'counterparty_column' => null,
            'external_id_column' => null,
            'currency' => 'EUR',
            'encoding' => null,
        ];

        $content = $this->toUtf8($this->removeBom($content));
        $lines = preg_split('/\R/', $content);
        $lines = array_slice($lines, (int) $options['skip']);
        $delimiter = 'auto' === $options['delimiter'] ? $this->guessDelimiter($lines[0] ?? '') : $options['delimiter'];

        $rows = array_map(fn (string $line) => str_getcsv($line, $delimiter, '"', '\\'), array_filter($lines, fn ($line) => '' !== trim($line)));
        $header = $options['header'] ? array_map('trim', array_shift($rows) ?? []) : null;

        $statement = new ParsedStatement(currencyCode: $options['currency']);

        foreach ($rows as $row) {
            $value = fn (int|string|null $column): ?string => $this->column($row, $header, $column);
            $date = $value($options['date_column']);

            if (null === $date || '' === trim($date)) {
                continue;
            }

            $amount = $this->readAmount($value, $options);
            $label = implode(' ', array_filter(array_map($value, (array) $options['label_columns'])));

            $statement->addTransaction(new ParsedTransaction(
                date: $this->parseDate($date, $options['date_format']),
                amount: $amount,
                label: $this->cleanLabel($label),
                externalId: $value($options['external_id_column']) ?: null,
                counterpartyName: $value($options['counterparty_column']) ?: null,
                reference: $value($options['reference_column']) ?: null,
            ));
        }

        if (isset($options['balance'], $options['balance_date'])) {
            $statement->addBalance(new ParsedBalance(
                $this->parseDate($options['balance_date'], $options['date_format']),
                $this->parseAmount((string) $options['balance'], $options['currency'])
            ));
        }

        return $statement;
    }

    private function readAmount(
        callable $value,
        array $options
    ): int {
        if (null !== $options['debit_column'] || null !== $options['credit_column']) {
            $debit = trim((string) $value($options['debit_column']));
            $credit = trim((string) $value($options['credit_column']));

            return ('' !== $credit ? $this->parseAmount($credit, $options['currency']) : 0)
                - ('' !== $debit ? abs($this->parseAmount($debit, $options['currency'])) : 0);
        }

        return $this->parseAmount((string) $value($options['amount_column']), $options['currency']);
    }

    private function column(
        array $row,
        ?array $header,
        int|string|null $column
    ): ?string {
        if (null === $column) {
            return null;
        }

        if (is_string($column)) {
            if (null === $header) {
                throw new AccountingException('Columns by name need a header row.');
            }

            $index = array_search($column, $header, true);

            return false === $index ? null : ($row[$index] ?? null);
        }

        return $row[$column] ?? null;
    }

    private function guessDelimiter(string $line): string
    {
        $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return array_key_first($counts);
    }
}
