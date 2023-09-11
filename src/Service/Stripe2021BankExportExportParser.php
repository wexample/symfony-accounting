<?php

namespace Wexample\SymfonyAccounting\Service;

use App\Entity\AccountingCode;
use App\Entity\AccountingTransaction;
use App\Entity\Invoice;
use App\Entity\Organization;
use App\Repository\InvoiceRepository;
use App\Repository\OrganizationRepository;
use App\Service\Entity\InvoiceEntityService;
use App\Service\Entity\InvoiceItemEntityService;
use App\Wex\BaseBundle\Helper\DateHelper;
use App\Wex\BaseBundle\Helper\TextHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use League\Csv\Exception;
use Wexample\SymfonyAccounting\Entity\AbstractBankOrganizationEntity;
use Wexample\SymfonyAccounting\Service\Entity\AbstractAccountingTransactionEntityService;

class Stripe2021BankExportExportParser extends AbstractBankExportParser
{
    public function __construct(
        EntityManagerInterface $entityManager,
        AbstractAccountingTransactionEntityService $accountingTransactionEntityService,
        protected InvoiceEntityService $invoiceService,
        private readonly InvoiceItemEntityService $invoiceItemService
    ) {
        parent::__construct($entityManager, $accountingTransactionEntityService);
    }

    /**
     * @throws OptimisticLockException
     * @throws ORMException
     * @throws NonUniqueResultException
     * @throws Exception
     * @throws NoResultException
     */
    public function parseContent(
        AbstractBankOrganizationEntity $bank,
        string $content,
        array $options = []
    ): int {
        /** @var InvoiceRepository $invoiceRepo */
        $invoiceRepo = $this->entityManager->getRepository(Invoice::class);
        $this->entityManager->getRepository(AccountingCode::class);
        /** @var OrganizationRepository $organizationRepo */
        $organizationRepo = $this->entityManager->getRepository(Organization::class);

        // Might change in the future.
        /** @var Organization $organizationStripe */
        $organizationStripe = $organizationRepo->find(
            OrganizationRepository::ORGANIZATION_ID_DEFAULT_COMPANY_BANK_STRIPE
        );

        $code = $organizationStripe->getAccountingCode();

        $csv = $this->createCsvFromBody($content, ',');
        $csv->setHeaderOffset(0);

        $count = 0;
        $em = $this->entityManager;
        $records = $csv->getRecords();
        foreach ($records as $record) {
            $this->saveNewTransactionFromStripeCsvRecord(
                $record,
                $bank,
                $count
            );

            // Create or update monthly stripe bill.
            if (TextHelper::getIntDataFromString($record['Fee'])) {
                $transactionFee = $this->saveNewTransactionFromStripeCsvRecord(
                    $record,
                    $bank,
                    $count,
                    'Fee',
                    true
                );

                $dateMonth = DateHelper::endOfMonth(
                    $transactionFee->getDateCreated()
                );

                $invoiceFees = $invoiceRepo->findOneByMonthAndAccountingCode(
                    $dateMonth,
                    $code
                );

                if (!$invoiceFees) {
                    $invoiceFees =
                        $this
                            ->invoiceService
                            ->createChargeOrProductFromTransaction($transactionFee);

                    $invoiceFees
                        ->setTitle(
                            'STRIPE FEES'
                        );

                    // Will be replaced.
                    $invoiceFees->setUser(
                        $organizationStripe->getUser()
                    );

                    $invoiceFees->setDateAccounting(
                        $dateMonth
                    );

                    $invoiceFees->setDatePaid(
                        $dateMonth
                    );

                    $invoiceFees->setAccountingCode(
                        $code
                    );

                    $em->persist($invoiceFees);
                    $em->flush();
                }

                $this->accountingTransactionRepo->assignUniqueValidTransactionToInvoice(
                    $transactionFee,
                    $invoiceFees
                );

                $this
                    ->invoiceItemService
                    ->createInvoiceItemForEachAccountingTransactionRelation(
                        $invoiceFees
                    );

                $this
                    ->invoiceService
                    ->saveInvoice($invoiceFees);
            }
        }

        return $count;
    }

    protected function saveNewTransactionFromStripeCsvRecord(
        array $record,
        AbstractBankOrganizationEntity $bank,
        int &$count,
        string $column = 'Amount',
        bool $negate = false
    ): AccountingTransaction {
        $transaction = $this->accountingTransactionRepo->createAccountingTransaction(
            $bank,
            AccountingTransaction::TYPE_TRANSACTION,
            $this->parseDate($record['Created (UTC)']),
            $record['Description'].' '.$column.' '.$record['id'],
            ($negate ? -1 : 1) * TextHelper::getIntDataFromString($record[$column])
        );

        if ($this->accountingTransactionEntityService->saveTransactionIfNotExists($transaction)) {
            ++$count;

            return $transaction;
        }

        return $this->accountingTransactionEntityService->findSameTransaction($transaction);
    }
}
