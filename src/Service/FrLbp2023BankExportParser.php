<?php

namespace Wexample\SymfonyAccounting\Service;

use League\Csv\TabularDataReader;
use Wexample\SymfonyHelpers\Helper\DateHelper;
use Wexample\SymfonyHelpers\Helper\TextHelper;

class FrLbp2023BankExportParser extends CsvWithMetadataBankExportParser
{
    public int $headerHeight = 6;

    protected function getDateExport(TabularDataReader $header): ?string
    {
        $line = $header->fetchOne(2)[1];

        preg_match_all(
            '/([0-9][0-9]\/[0-9][0-9]\/[0-9][0-9][0-9][0-9])/m',
            $line,
            $matches,
            PREG_SET_ORDER,
        );

        return $matches[0][0];
    }

    protected function getDateFormat(): string
    {
        return DateHelper::DATE_PATTERN_YMD_FR;
    }

    protected function getAccountBalanceStatement(TabularDataReader $header): ?int
    {
        return TextHelper::getIntDataFromString($header->fetchOne(4)[1]);
    }

    protected function getRecordDescription(array $record): string
    {
        return $record[1];
    }

    protected function getRecordDateString(array $record): string
    {
        return $record[0];
    }

    protected function getRecordAmountString(array $record): string
    {
        return $record[2];
    }
}
