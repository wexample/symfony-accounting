<?php

namespace Wexample\SymfonyAccounting\Service;

use League\Csv\TabularDataReader;
use Wexample\SymfonyAccounting\Entity\AbstractBankOrganizationEntity;
use Wexample\SymfonyHelpers\Helper\DateHelper;

class FrLbp2023BankExportParser extends CsvWithMetadataBankExportParser
{
    public int $headerHeight = 6;

    public function parseContent(
        AbstractBankOrganizationEntity $bank,
        string $content,
        array $options = []
    ): int {
        return $this->convertCsvTextToTransaction($bank, $content);
    }

    protected function getCreated(TabularDataReader $header): string
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
}
