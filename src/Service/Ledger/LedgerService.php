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
use Wexample\SymfonyOrganization\Entity\Organization;
use Wexample\SymfonyOrganization\Service\OrganizationService;

/**
 * Opening a set of books for an organization of the directory: journals,
 * chart, first fiscal year.
 */
class LedgerService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
        private readonly JournalRepository $journalRepository,
        private readonly ChartService $chartService,
        private readonly FiscalYearService $fiscalYearService,
        private readonly OrganizationService $organizationService,
    ) {
    }

    /**
     * @param Organization|string $organization The organization keeping these books, or the
     *                                          name of a new one, in $country.
     * @param Country|null $country Required with a name; with an organization, its own country.
     * @param DateTimeImmutable|null $fiscalYearStart Creates the first fiscal year (twelve months) when given.
     */
    public function create(
        Organization|string $organization,
        ?Country $country = null,
        bool $loadChart = true,
        ?DateTimeImmutable $fiscalYearStart = null,
        string $currencyCode = 'EUR',
    ): Ledger {
        if (is_string($organization)) {
            if (! $country) {
                throw new AccountingException(sprintf('Books for "%s" need a country.', $organization));
            }

            $organization = $this->organizationService->create($organization, $country);
        } elseif ($country && ! $organization->getCountry()) {
            $organization->setCountry($country);
        }

        if (! $organization->getCountry()) {
            throw new AccountingException(sprintf('"%s" has no country: its books need a jurisdiction.', $organization->getName()));
        }

        $ledger = (new Ledger())
            ->setOrganization($organization)
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
