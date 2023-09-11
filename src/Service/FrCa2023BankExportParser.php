<?php

namespace Wexample\SymfonyAccounting\Service;

use League\Csv\TabularDataReader;
use Wexample\SymfonyAccounting\Entity\AbstractBankOrganizationEntity;
use Wexample\SymfonyHelpers\Helper\DateHelper;

class FrCa2023BankExportParser extends CsvWithMetadataBankExportParser
{
    public int $headerHeight = 10;

    public function parseContent(
        AbstractBankOrganizationEntity $bank,
        string $content,
        array $options = []
    ): int {
        return $this->convertCsvTextToTransaction($bank, $content);
    }

    protected function getDateExport(TabularDataReader $header): ?string
    {
        return false;
    }

    protected function getDateFormat(): string
    {
        return DateHelper::DATE_PATTERN_YMD_FR;
    }

    protected function getAccountBalanceStatement(TabularDataReader $header): ?int
    {
        return false;
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
        # Credit, or debit.
        return $record[3] ?: '-'.$record[2];
    }
}
