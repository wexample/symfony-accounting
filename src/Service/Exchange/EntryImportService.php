<?php

namespace Wexample\SymfonyAccounting\Service\Exchange;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Wexample\SymfonyAccounting\Class\EntryImportResult;
use Wexample\SymfonyAccounting\Class\ImportedLine;
use Wexample\SymfonyAccounting\Entity\EntryLine;
use Wexample\SymfonyAccounting\Entity\Journal;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\EntryStatus;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Interface\EntryImporterInterface;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\JournalRepository;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearService;
use Wexample\SymfonyAccounting\Service\Ledger\PostingService;

/**
 * Books the entries of another bookkeeping in a ledger: what an accounting firm
 * needs to take over a client's books.
 *
 * Missing accounts, journals and auxiliary accounts (as parties) are created;
 * fiscal years too when asked. Each entry is checked on its own: an unbalanced
 * one is reported and skipped, the others are booked. Letters and validation
 * dates are kept.
 */
class EntryImportService
{
    /**
     * @param iterable<EntryImporterInterface> $importers
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChartService $chartService,
        private readonly PostingService $postingService,
        private readonly FiscalYearService $fiscalYearService,
        private readonly FiscalYearRepository $fiscalYearRepository,
        private readonly JournalRepository $journalRepository,
        private readonly PartyRepository $partyRepository,
        private readonly iterable $importers = [],
    ) {
    }

    public function getImporter(string $key): EntryImporterInterface
    {
        foreach ($this->importers as $importer) {
            if ($importer->getKey() === $key) {
                return $importer;
            }
        }

        throw new AccountingException(sprintf('No entry importer "%s".', $key));
    }

    public function importContent(
        Ledger $ledger,
        string $content,
        ?string $importerKey = null,
        array $options = []
    ): EntryImportResult {
        $importer = null;

        if ($importerKey) {
            $importer = $this->getImporter($importerKey);
        } else {
            foreach ($this->importers as $candidate) {
                if ($candidate->supports($content)) {
                    $importer = $candidate;

                    break;
                }
            }
        }

        if (! $importer) {
            throw new AccountingException('The format of this file is not recognized.');
        }

        return $this->import($ledger, $importer->read($content, $options), $options);
    }

    /**
     * @param iterable<ImportedLine> $lines
     * @param array{create_fiscal_years?: bool, fiscal_year_start_month?: int} $options
     */
    public function import(
        Ledger $ledger,
        iterable $lines,
        array $options = []
    ): EntryImportResult {
        $result = new EntryImportResult();
        $groups = [];

        foreach ($lines as $line) {
            $groups[$line->journalCode.'/'.$line->entryNumber][] = $line;
        }

        $customersPrefix = $this->chartService->getRoleNumber($ledger, AccountRole::Customers);
        $suppliersPrefix = $this->chartService->getRoleNumber($ledger, AccountRole::Suppliers);

        foreach ($groups as $key => $group) {
            try {
                $this->importEntry($ledger, $group, $options, $result, $customersPrefix, $suppliersPrefix);
            } catch (AccountingException $exception) {
                $result->errors[$key] = $exception->getMessage();
            }
        }

        $this->entityManager->flush();

        return $result;
    }

    /**
     * @param list<ImportedLine> $group
     */
    private function importEntry(
        Ledger $ledger,
        array $group,
        array $options,
        EntryImportResult $result,
        string $customersPrefix,
        string $suppliersPrefix
    ): void {
        $first = $group[0];
        $debit = array_sum(array_map(fn (ImportedLine $l) => $l->debit, $group));
        $credit = array_sum(array_map(fn (ImportedLine $l) => $l->credit, $group));

        if ($debit !== $credit) {
            throw new AccountingException(sprintf('Unbalanced: %d debit, %d credit.', $debit, $credit));
        }

        $fiscalYear = $this->fiscalYearRepository->findForDate($ledger, $first->date);

        if (! $fiscalYear && ($options['create_fiscal_years'] ?? false)) {
            $startMonth = (int) ($options['fiscal_year_start_month'] ?? 1);
            $year = (int) $first->date->format('Y') - ((int) $first->date->format('n') < $startMonth ? 1 : 0);
            $fiscalYear = $this->fiscalYearService->create($ledger, new DateTimeImmutable(sprintf('%d-%02d-01', $year, $startMonth)));
            ++$result->fiscalYearsCreated;
        }

        if (! $fiscalYear) {
            throw new AccountingException(sprintf('No fiscal year covers %s.', $first->date->format('Y-m-d')));
        }

        $entry = (new JournalEntry())
            ->setLedger($ledger)
            ->setJournal($this->getJournal($ledger, $first, $result))
            ->setFiscalYear($fiscalYear)
            ->setDate($first->date)
            ->setLabel($first->label)
            ->setPieceReference($first->pieceReference)
            ->setPieceDate($first->pieceDate ?? $first->date)
            ->setSource('import', $first->journalCode.'/'.$first->entryNumber);

        foreach ($group as $imported) {
            $account = $this->chartService->findAccount($ledger, $imported->accountNumber);

            if (! $account) {
                $account = $this->chartService->getAccount($ledger, $imported->accountNumber);
                ++$result->accountsCreated;

                if ($imported->accountLabel) {
                    $account->setLabel($imported->accountLabel);
                }
            }

            $party = $imported->auxiliaryCode
                ? $this->getParty($ledger, $imported, str_starts_with($imported->accountNumber, $customersPrefix), $result)
                : null;

            if ($party && ! $account->isLettrable()) {
                $account->setLettrable(true);
            }

            $line = (new EntryLine())
                ->setAccount($account)
                ->setParty($party)
                ->setLabel($imported->label)
                ->setAmount($imported->debit - $imported->credit)
                ->setCurrency($imported->currencyAmount, $imported->currencyCode);

            if ($imported->letter) {
                $line->setLetter($imported->letter, $imported->dateLettered);
            }

            $entry->addLine($line);
        }

        $this->postingService->post($entry);

        if ($first->dateValidated) {
            $entry->setStatus(EntryStatus::Validated)->setDateValidated($first->dateValidated);
        }

        ++$result->entries;
        $result->lines += count($group);
    }

    private function getJournal(
        Ledger $ledger,
        ImportedLine $line,
        EntryImportResult $result
    ): Journal {
        $journal = $this->journalRepository->findOneByCode($ledger, $line->journalCode);

        if (! $journal) {
            $journal = (new Journal())
                ->setLedger($ledger)
                ->setCode($line->journalCode)
                ->setLabel($line->journalLabel ?? $line->journalCode)
                ->setType($this->guessJournalType($line->journalCode, (string) $line->journalLabel));
            $this->entityManager->persist($journal);
            $this->entityManager->flush();
            ++$result->journalsCreated;
        }

        return $journal;
    }

    private function getParty(
        Ledger $ledger,
        ImportedLine $line,
        bool $customer,
        EntryImportResult $result
    ): Party {
        $party = $customer
            ? $this->partyRepository->findOneByCustomerCode($ledger, $line->auxiliaryCode)
            : $this->partyRepository->findOneBySupplierCode($ledger, $line->auxiliaryCode);

        if (! $party) {
            $party = (new Party())
                ->setLedger($ledger)
                ->setName($line->auxiliaryLabel ?? $line->auxiliaryCode)
                ->setCustomer($customer)
                ->setSupplier(! $customer);
            $customer ? $party->setCustomerCode($line->auxiliaryCode) : $party->setSupplierCode($line->auxiliaryCode);
            $this->entityManager->persist($party);
            $this->entityManager->flush();
            ++$result->partiesCreated;
        }

        return $party;
    }

    private function guessJournalType(
        string $code,
        string $label
    ): JournalType {
        $text = strtoupper($code.' '.$label);

        return match (true) {
            1 === preg_match('/\b(AN|RAN|OUV)/', $text) || str_contains($text, 'NOUVEAU') || str_contains($text, 'OPENING') => JournalType::Opening,
            1 === preg_match('/^(VE|VT|V\d)/', $code) || str_contains($text, 'VENTE') || str_contains($text, 'SALE') => JournalType::Sales,
            1 === preg_match('/^(AC|HA|A\d)/', $code) || str_contains($text, 'ACHAT') || str_contains($text, 'PURCHASE') => JournalType::Purchases,
            1 === preg_match('/^(BQ|BK|B\d)/', $code) || str_contains($text, 'BANQUE') || str_contains($text, 'BANK') => JournalType::Bank,
            1 === preg_match('/^(CA|CS)/', $code) || str_contains($text, 'CAISSE') || str_contains($text, 'CASH') => JournalType::Cash,
            default => JournalType::Miscellaneous,
        };
    }
}
