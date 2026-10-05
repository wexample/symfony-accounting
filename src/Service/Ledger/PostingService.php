<?php

namespace Wexample\SymfonyAccounting\Service\Ledger;

use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Entity\EntryLine;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\EntryStatus;
use Wexample\SymfonyAccounting\Event\EntryPostedEvent;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Exception\ClosedFiscalYearException;
use Wexample\SymfonyAccounting\Exception\UnbalancedEntryException;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;

/**
 * The only way into the books.
 *
 * Posting checks the entry balances and its fiscal year is open, then numbers it
 * without gaps within the year. A booked entry is never deleted: it is reversed.
 */
class PostingService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChartService $chartService,
        private readonly LedgerService $ledgerService,
        private readonly FiscalYearService $fiscalYearService,
        private readonly JournalEntryRepository $entryRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Builds the entry of a draft without booking it.
     */
    public function build(
        Ledger $ledger,
        EntryDraft $draft
    ): JournalEntry {
        $entry = (new JournalEntry())
            ->setLedger($ledger)
            ->setJournal($this->ledgerService->getJournal($ledger, $draft->journal))
            ->setFiscalYear($this->fiscalYearService->getOpenForDate($ledger, $draft->date))
            ->setDate($draft->date)
            ->setLabel($draft->label)
            ->setPieceReference($draft->pieceReference)
            ->setPieceDate($draft->pieceDate ?? $draft->date)
            ->setSource($draft->sourceType, $draft->sourceId);

        foreach ($draft->getLines() as $line) {
            $account = $line['account'] instanceof AccountRole
                ? $this->chartService->getRoleAccount($ledger, $line['account'])
                : $this->chartService->getAccount($ledger, $line['account']);

            $entry->addLine(
                (new EntryLine())
                    ->setAccount($account)
                    ->setAmount($line['amount'])
                    ->setLabel($line['label'])
                    ->setParty($line['party'])
                    ->setVat($line['vatCode'], $line['vatRole'])
                    ->setDateDue($line['dateDue'])
            );
        }

        return $entry;
    }

    public function postDraft(
        Ledger $ledger,
        EntryDraft $draft
    ): JournalEntry {
        return $this->post($this->build($ledger, $draft));
    }

    /**
     * Books an entry: checks, numbers, persists.
     */
    public function post(JournalEntry $entry): JournalEntry
    {
        if (EntryStatus::Draft !== $entry->getStatus()) {
            throw new AccountingException('This entry is already booked.');
        }

        $this->assertBookable($entry);

        $this->entityManager->wrapInTransaction(function () use ($entry): void {
            $fiscalYear = $entry->getFiscalYear();
            $this->entityManager->persist($entry);

            if ($this->entityManager->contains($fiscalYear) && $this->entityManager->getUnitOfWork()->isInIdentityMap($fiscalYear)) {
                $this->entityManager->lock($fiscalYear, LockMode::PESSIMISTIC_WRITE);
            }

            $entry
                ->setNumber($this->nextNumber($entry))
                ->setStatus(EntryStatus::Posted);
        });

        $this->eventDispatcher->dispatch(new EntryPostedEvent($entry));

        return $entry;
    }

    /**
     * Cancels a booked entry by booking its exact opposite.
     */
    public function reverse(
        JournalEntry $entry,
        ?DateTimeImmutable $date = null,
        ?string $label = null
    ): JournalEntry {
        if (EntryStatus::Draft === $entry->getStatus()) {
            throw new AccountingException('A draft is deleted, not reversed.');
        }

        $date ??= $entry->getDate();
        $reversal = (new JournalEntry())
            ->setLedger($entry->getLedger())
            ->setJournal($entry->getJournal())
            ->setFiscalYear($this->fiscalYearService->getOpenForDate($entry->getLedger(), $date))
            ->setDate($date)
            ->setLabel($label ?? 'Reversal: '.$entry->getLabel())
            ->setPieceReference($entry->getPieceReference())
            ->setPieceDate($entry->getPieceDate())
            ->setSource($entry->getSourceType(), $entry->getSourceId());

        foreach ($entry->getLines() as $line) {
            $reversal->addLine(
                (new EntryLine())
                    ->setAccount($line->getAccount())
                    ->setParty($line->getParty())
                    ->setLabel($line->getLabel())
                    ->setAmount(-$line->getBalance())
                    ->setVat($line->getVatCode(), $line->getVatRole())
                    ->setDateDue($line->getDateDue())
            );
        }

        return $this->post($reversal);
    }

    /**
     * Drafts only: a booked entry stays in the books.
     */
    public function delete(JournalEntry $entry): void
    {
        if (EntryStatus::Draft !== $entry->getStatus()) {
            throw new AccountingException('A booked entry cannot be deleted; reverse it.');
        }

        $this->entityManager->remove($entry);
        $this->entityManager->flush();
    }

    /**
     * Validates booked entries up to a date (FEC ValidDate): they become final.
     *
     * @return int The number of entries validated.
     */
    public function validateUntil(
        Ledger $ledger,
        DateTimeImmutable $until
    ): int {
        $count = 0;
        $now = new DateTimeImmutable();

        foreach ($this->entryRepository->findBy(['ledger' => $ledger, 'status' => EntryStatus::Posted]) as $entry) {
            if ($entry->getDate() <= $until) {
                $entry->setStatus(EntryStatus::Validated)->setDateValidated($now);
                ++$count;
            }
        }

        $this->entityManager->flush();

        return $count;
    }

    public function assertBookable(JournalEntry $entry): void
    {
        $fiscalYear = $entry->getFiscalYear();

        if ($fiscalYear->isClosed()) {
            throw new ClosedFiscalYearException($fiscalYear);
        }

        if (! $fiscalYear->contains($entry->getDate())) {
            throw new AccountingException(sprintf(
                'The entry date %s is outside fiscal year %s.',
                $entry->getDate()->format('Y-m-d'),
                $fiscalYear->getLabel()
            ));
        }

        if ($entry->getLines()->count() < 2) {
            throw new AccountingException('An entry needs at least two lines.');
        }

        foreach ($entry->getLines() as $line) {
            if (0 === $line->getDebit() && 0 === $line->getCredit()) {
                throw new AccountingException('An entry line cannot be zero.');
            }

            if ($line->getAccount()->getLedger() !== $entry->getLedger()) {
                throw new AccountingException('An entry line uses an account of another ledger.');
            }
        }

        if (! $entry->isBalanced()) {
            throw new UnbalancedEntryException($entry);
        }
    }

    private function nextNumber(JournalEntry $entry): int
    {
        // Flush pending entries of the same year first, so MAX() sees them.
        $this->entityManager->flush();
        $max = $this->entryRepository->findMaxNumber($entry->getFiscalYear());

        return $max + 1;
    }
}
