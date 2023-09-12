<?php

namespace Wexample\SymfonyAccounting\Service;

use League\Csv\Exception;
use League\Csv\Reader;
use League\Csv\Statement;
use League\Csv\TabularDataReader;
use League\Csv\UnableToProcessCsv;
use Wexample\SymfonyAccounting\Entity\AbstractAccountingTransactionEntity;
use Wexample\SymfonyAccounting\Entity\AbstractBankOrganizationEntity;
use Wexample\SymfonyHelpers\Helper\DateHelper;
use Wexample\SymfonyHelpers\Helper\TextHelper;
use function trim;

abstract class CsvWithMetadataBankExportParser extends AbstractBankExportParser
{
    public int $headerHeight;

    /**
     * @throws UnableToProcessCsv
     * @throws Exception
     */
    public function parseContent(
        AbstractBankOrganizationEntity $bank,
        $content,
        array $options = []
    ): int {
        return $this->convertCsvTextToTransaction($bank, $content);
    }

    /**
     * @throws UnableToProcessCsv
     */
    public function convertCsvTextToTransaction(
        AbstractBankOrganizationEntity $bank,
        string $text
    ): int {
        $csv = $this->createCsvFromBody($text);

        // Load header.
        $header = (new Statement())->process($csv);
        $created = $this->getDateExport($header);
        $balanceStatement = $this->getAccountBalanceStatement($header);

        if ($created && $balanceStatement) {
            $dateCreated = $this->parseDate($created, $this->getDateFormat());

            // Save first transaction as statement.
            $this->saveTransactionOfNotExists(
                $bank,
                $dateCreated,
                AbstractAccountingTransactionEntity::TYPE_STATEMENT.' - '
                .$dateCreated->format(DateHelper::DATE_PATTERN_DAY_DEFAULT),
                $balanceStatement
            );
        }

        return $this->convertCsvRecords(
            $bank,
            $csv,
            $this->headerHeight
        );
    }

    abstract protected function getDateExport(TabularDataReader $header): ?string;

    abstract protected function getAccountBalanceStatement(TabularDataReader $header): ?int;

    abstract protected function getDateFormat(): string;

    /**
     * @throws Exception
     */
    public function convertCsvRecords(
        AbstractBankOrganizationEntity $bank,
        Reader $csv,
        int $offset = 0
    ): int {
        $count = 0;
        // Load contents.
        $stmt = new Statement();

        $records = $stmt
            ->offset($offset)
            ->process($csv);

        /** @var array $record */
        foreach ($records as $record) {
            $desc = trim(
                $this->getRecordDescription($record)
            );
            $date = $this->parseDate(
                $this->getRecordDateString($record),
                $this->getDateFormat()
            );
            $amount = TextHelper::getIntDataFromString(
                $this->getRecordAmountString($record)
            );

            if ($this->saveTransactionOfNotExists(
                $bank,
                $date,
                $desc,
                $amount
            )) {
                ++$count;
            }
        }

        return $count;
    }

    abstract protected function getRecordDescription(array $record): string;

    abstract protected function getRecordDateString(array $record): string;

    abstract protected function getRecordAmountString(array $record): string;
}
