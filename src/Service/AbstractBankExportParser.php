<?php

namespace Wexample\SymfonyAccounting\Service;

use App\Entity\AccountingTransaction;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use League\Csv\Exception;
use League\Csv\Reader;
use Wexample\SymfonyAccounting\Entity\AbstractAccountingTransactionEntity;
use Wexample\SymfonyAccounting\Entity\AbstractBankOrganizationEntity;
use Wexample\SymfonyAccounting\Service\Entity\AbstractAccountingTransactionEntityService;
use Wexample\SymfonyHelpers\Helper\DateHelper;
use function file_get_contents;

abstract class AbstractBankExportParser
{
    public EntityRepository $accountingTransactionRepo;

    public function __construct(
        public EntityManagerInterface $entityManager,
        protected readonly AbstractAccountingTransactionEntityService $accountingTransactionEntityService
    ) {
        $this->accountingTransactionRepo = $this->entityManager->getRepository(AccountingTransaction::class);
    }

    public function createCsvFromBody(
        string $text,
        string $separator = ';'
    ): ?Reader {
        try {
            $csv = Reader::createFromString($text);
            $csv->setDelimiter($separator);

            return $csv;
        } catch (Exception) {
        }

        return null;
    }

    public function parseFile(
        AbstractBankOrganizationEntity $bank,
        string $filePath,
        array $options = []
    ): int {
        $options['filepath'] = $filePath;

        return $this->parseContent(
            $bank,
            file_get_contents($filePath),
            $options
        );
    }

    abstract public function parseContent(
        AbstractBankOrganizationEntity $bank,
        $content,
        array $options = []
    ): int;

    public function parseDate(
        string $dateString,
        string $format = 'Y-m-d H:i'
    ): DateTimeInterface {
        // Set time at midnight.
        return DateHelper::startOfDay(DateTime::createFromFormat(
            $format,
            trim($dateString)
        ));
    }

    public function saveTransactionOfNotExists(
        AbstractBankOrganizationEntity $bank,
        DateTimeInterface $date,
        string $description,
        int $amount
    ): bool {
        $transaction = $this->accountingTransactionRepo->createAccountingTransaction(
            $bank,
            AbstractAccountingTransactionEntity::TYPE_TRANSACTION,
            $date,
            $description,
            $amount
        );

        return $this
            ->accountingTransactionEntityService
            ->saveTransactionIfNotExists(
                $transaction
            );
    }
}
