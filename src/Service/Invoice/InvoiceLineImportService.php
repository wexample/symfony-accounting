<?php

namespace Wexample\SymfonyAccounting\Service\Invoice;

use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyMoney\Helper\MoneyHelper;

/**
 * Lines of a draft taken from a table: rows pasted from a spreadsheet, or read
 * from a file, with the column each field is in. Numbers are read as people type
 * them ("1 234,50 €", "1,234.50", "20 %"). Every row is checked before any line
 * is added: a table with one bad row adds nothing.
 */
class InvoiceLineImportService
{
    public const string FIELD_TITLE = 'title';
    public const string FIELD_DESCRIPTION = 'description';
    public const string FIELD_UNIT_PRICE = 'unitPrice';
    public const string FIELD_QUANTITY = 'quantity';
    public const string FIELD_VAT_RATE = 'vatRate';

    public const array FIELDS = [self::FIELD_TITLE, self::FIELD_DESCRIPTION, self::FIELD_UNIT_PRICE, self::FIELD_QUANTITY, self::FIELD_VAT_RATE];

    public function __construct(
        private readonly InvoiceFactory $invoiceFactory,
    ) {
    }

    /**
     * @param list<list<?string>> $rows    The table without its header row. A row whose mapped cells are all blank is skipped.
     * @param array<string, int>  $columns Field (see FIELDS) => index of its column; title and unit price required.
     *                                     No quantity column means one; no VAT column, the ledger's default rate.
     * @return list<InvoiceItem>
     */
    public function import(
        Invoice $invoice,
        array $rows,
        array $columns
    ): array {
        if ($unknown = array_diff(array_keys($columns), self::FIELDS)) {
            throw new AccountingException(sprintf('Unknown fields: %s.', implode(', ', $unknown)));
        }

        if (! isset($columns[self::FIELD_TITLE], $columns[self::FIELD_UNIT_PRICE])) {
            throw new AccountingException('The title and the unit price need a column.');
        }

        $lines = [];
        foreach (array_values($rows) as $index => $row) {
            $cells = array_map(fn (int $column) => trim((string) ($row[$column] ?? '')), $columns);

            if ('' === implode('', $cells)) {
                continue;
            }

            $lines[] = $this->line($cells, $index + 1, $invoice->getCurrencyCode());
        }

        return array_map(
            fn (array $line) => $this->invoiceFactory
                ->addItem($invoice, $line[self::FIELD_TITLE], $line[self::FIELD_UNIT_PRICE], $line[self::FIELD_QUANTITY], $line[self::FIELD_VAT_RATE])
                ->setDescription($line[self::FIELD_DESCRIPTION]),
            $lines
        );
    }

    private function line(
        array $cells,
        int $row,
        string $currency
    ): array {
        $cell = fn (string $field) => $cells[$field] ?? '';

        if ('' === $cell(self::FIELD_TITLE)) {
            throw new AccountingException(sprintf('Row %d has no title.', $row));
        }

        if (! $this->isNumber($cell(self::FIELD_UNIT_PRICE))) {
            throw new AccountingException(sprintf('Row %d: "%s" is no price.', $row, $cell(self::FIELD_UNIT_PRICE)));
        }

        foreach ([self::FIELD_QUANTITY, self::FIELD_VAT_RATE] as $field) {
            if ('' !== $cell($field) && ! $this->isNumber($cell($field))) {
                throw new AccountingException(sprintf('Row %d: "%s" is no %s.', $row, $cell($field), $field));
            }
        }

        return [
            self::FIELD_TITLE => $cell(self::FIELD_TITLE),
            self::FIELD_DESCRIPTION => $cell(self::FIELD_DESCRIPTION) ?: null,
            self::FIELD_UNIT_PRICE => MoneyHelper::fromDecimal($cell(self::FIELD_UNIT_PRICE), $currency),
            self::FIELD_QUANTITY => '' === $cell(self::FIELD_QUANTITY)
                ? InvoiceItem::QUANTITY_SCALE
                : (int) round($this->number($cell(self::FIELD_QUANTITY)) * InvoiceItem::QUANTITY_SCALE),
            self::FIELD_VAT_RATE => '' === $cell(self::FIELD_VAT_RATE) ? null : $this->rate($cell(self::FIELD_VAT_RATE)),
        ];
    }

    /**
     * A rate in basis points: "20", "20 %", "5,5" are percentages; "0.2", as a
     * spreadsheet stores a percent cell, is a fraction.
     */
    private function rate(string $cell): int
    {
        $value = $this->number($cell);

        return (int) round(($value > 0 && $value < 1 && ! str_contains($cell, '%') ? $value * 100 : $value) * 100);
    }

    private function number(string $cell): float
    {
        return (float) MoneyHelper::normalizeDecimalString($cell);
    }

    private function isNumber(string $cell): bool
    {
        return 1 === preg_match('/\d/', $cell) && is_numeric(MoneyHelper::normalizeDecimalString($cell));
    }
}
