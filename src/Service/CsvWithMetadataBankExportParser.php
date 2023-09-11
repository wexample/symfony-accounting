<?php

namespace Wexample\SymfonyAccounting\Service;

use Wexample\SymfonyAccounting\Entity\AbstractAccountingTransactionEntity;
use Wexample\SymfonyAccounting\Entity\AbstractBankOrganizationEntity;
use Wexample\SymfonyAccounting\Service\Entity\AbstractAccountingTransactionEntityService;
use Wexample\SymfonyHelpers\Helper\TextHelper;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Exception;
use League\Csv\Reader;
use League\Csv\Statement;
use League\Csv\TabularDataReader;
use League\Csv\UnableToProcessCsv;
use function trim;

abstract class CsvWithMetadataBankExportParser extends AbstractBankExportParser
{
    public int $headerHeight = 7;

    public function __construct(
        EntityManagerInterface $entityManager,
        protected readonly AbstractAccountingTransactionEntityService $accountingTransactionEntityService
    ) {
        parent::__construct($entityManager);
    }

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
        $created = $this->getCreated($header);

        // Save first transaction as statement.
        $transaction = $this->accountingTransactionRepo->createAccountingTransaction(
            $bank,
            AbstractAccountingTransactionEntity::TYPE_STATEMENT,
            $this->parseDate($created, $this->getDateFormat()),
            AbstractAccountingTransactionEntity::TYPE_STATEMENT.' - '.$created,
            TextHelper::getIntDataFromString($header->fetchOne(4)[1])
        );

        $this->accountingTransactionEntityService->saveTransactionIfNotExists($transaction);

        return $this->convertCsvRecords(
            $bank,
            $csv,
            $this->headerHeight
        );
    }

    abstract protected function getCreated(TabularDataReader $header): string;

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
            $desc = trim($record[1]);

            $transaction = $this->accountingTransactionRepo->createAccountingTransaction(
                $bank,
                AbstractAccountingTransactionEntity::TYPE_TRANSACTION,
                $this->parseDate($record[0], $this->getDateFormat()),
                $desc,
                TextHelper::getIntDataFromString($record[2])
            );

            if ($this->accountingTransactionEntityService->saveTransactionIfNotExists($transaction)) {
                ++$count;
            }
        }

        return $count;
    }
}
