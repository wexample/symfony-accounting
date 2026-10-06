<?php

namespace Wexample\SymfonyAccounting\Service\Ledger;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Wexample\SymfonyAccounting\Entity\Journal;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\JournalRepository;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyGeo\Entity\Country;

/**
 * Opening a set of books: identity, journals, chart, first fiscal year.
 */
class LedgerService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
        private readonly JournalRepository $journalRepository,
        private readonly ChartService $chartService,
        private readonly FiscalYearService $fiscalYearService,
    ) {
    }

    /**
     * @param DateTimeImmutable|null $fiscalYearStart Creates the first fiscal year (twelve months) when given.
     */
    public function create(
        string $name,
        Country $country,
        bool $loadChart = true,
        ?DateTimeImmutable $fiscalYearStart = null,
        string $currencyCode = 'EUR',
    ): Ledger {
        $ledger = (new Ledger())
            ->setName($name)
            ->setCountry($country)
            ->setCurrencyCode($currencyCode);

        $this->entityManager->persist($ledger);
        $this->createDefaultJournals($ledger);

        if ($loadChart) {
            $this->chartService->loadDataset($ledger);
        }

        if ($fiscalYearStart) {
            $this->fiscalYearService->create($ledger, $fiscalYearStart);
        }

        $this->entityManager->flush();

        return $ledger;
    }

    public function createDefaultJournals(Ledger $ledger): void
    {
        foreach ($this->jurisdictionRegistry->forLedger($ledger)->getDefaultJournals() as $code => $definition) {
            if (! $this->journalRepository->findOneByCode($ledger, $code)) {
                $this->entityManager->persist(
                    (new Journal())
                        ->setLedger($ledger)
                        ->setCode($code)
                        ->setLabel($definition['label'])
                        ->setType($definition['type'])
                );
            }
        }

        $this->entityManager->flush();
    }

    public function getJournal(
        Ledger $ledger,
        JournalType|string $typeOrCode
    ): Journal {
        $journal = is_string($typeOrCode)
            ? $this->journalRepository->findOneByCode($ledger, $typeOrCode)
            : $this->journalRepository->findOneByType($ledger, $typeOrCode);

        if (! $journal) {
            throw new AccountingException(sprintf(
                'The ledger has no journal "%s".',
                is_string($typeOrCode) ? $typeOrCode : $typeOrCode->value
            ));
        }

        return $journal;
    }
}
