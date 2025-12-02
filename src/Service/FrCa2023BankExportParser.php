<?php

namespace Wexample\SymfonyAccounting\Service;

use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Row;
use Wexample\SymfonyHelpers\Helper\DateHelper;

class FrCa2023BankExportParser extends XlsBankExportParser
{
    public int $headerHeight = 11;

    protected function getRowDescription(Row $row): string
    {
        return $this->getCellValue($row, 'B');
    }

    protected function getCellValue(
        Row $row,
        $cellCoordinate,
        $default = null
    ): ?string {
        $worksheet = $row->getWorksheet();

        $cell = $worksheet->getCell($cellCoordinate.$row->getRowIndex());

        return $cell->getValue() ?? $default;
    }

    protected function getRowDateString(Row $row): string
    {
        return Date::excelToDateTimeObject(
            $this->getCellValue($row, 'A')
        )->format($this->getDateFormat());
    }

    protected function getDateFormat(): string
    {
        return DateHelper::DATE_PATTERN_YMD_FR;
    }

    protected function getRowAmountString(Row $row): string
    {
        // Credit or debit.
        return $this->getCellValue($row, 'D')
            ?: ('-' . $this->getCellValue($row, 'C'));
    }
}
