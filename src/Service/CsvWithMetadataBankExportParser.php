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
    public int $headerHeight = 10;

    /**
     * @throws UnableToProcessCsv
     * @throws Exception
     */
    public function parseContent(
        AbstractBankOrganizationEntity $bank,
        string $content,
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
            $transaction = $this->accountingTransactionRepo->createAccountingTransaction(
                $bank,
                AbstractAccountingTransactionEntity::TYPE_STATEMENT,
                $dateCreated,
                AbstractAccountingTransactionEntity::TYPE_STATEMENT.' - '
                .$dateCreated->format(DateHelper::DATE_PATTERN_DAY_DEFAULT),
                $balanceStatement
            );

            $this->accountingTransactionEntityService->saveTransactionIfNotExists($transaction);
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

        foreach ($records as $record) {
            $desc = trim(
                $record[$this->getItemDescriptionColumnIndex()]
            );

            $transaction = $this->accountingTransactionRepo->createAccountingTransaction(
                $bank,
                AbstractAccountingTransactionEntity::TYPE_TRANSACTION,
                $this->parseDate($record[$this->getItemDateColumnIndex()], $this->getDateFormat()),
                $desc,
                TextHelper::getIntDataFromString(
                    $record[$this->getItemAmountColumnIndex()]
                )
            );

            if ($this->accountingTransactionEntityService->saveTransactionIfNotExists($transaction)) {
                ++$count;
            }
        }

        return $count;
    }

    abstract protected function getItemDescriptionColumnIndex(): int;

    abstract protected function getItemDateColumnIndex(): int;

    abstract protected function getItemAmountColumnIndex(): int;
}
