<?php

namespace Wexample\SymfonyAccounting\Service\Ledger;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\EntryStatus;
use Wexample\SymfonyAccounting\Enum\FiscalYearStatus;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Event\FiscalYearClosedEvent;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\EntryLineRepository;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyCheck\Class\CheckReport;
use Wexample\SymfonyCheck\Enum\Severity;
use Wexample\SymfonyCheck\Service\CheckService;

/**
 * Closing a fiscal year.
 *
 * 1. Checks (any checker of FiscalYear): an error blocks, unless forced.
 * 2. The result: income minus charges, on net balances (debits of income
 *    accounts and credits of charge accounts count too, which network forgot).
 * 3. Opening entries in the next year, journal "opening": every balance-sheet
 *    account carried with its balance, party by party, and the result on the
 *    profit or loss account, where it waits for its appropriation.
 * 4. Lock: no entry can be added to the year anymore.
 *
 * Closing twice does nothing; the balances are read from the entries, never from
 * a cache, so a first close is always right.
 */
class FiscalYearClosingService
{
    public const string SOURCE_OPENING = 'fiscal_year_opening';
    public const string SOURCE_APPROPRIATION = 'result_appropriation';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly FiscalYearRepository $fiscalYearRepository,
        private readonly FiscalYearService $fiscalYearService,
        private readonly JournalEntryRepository $entryRepository,
        private readonly EntryLineRepository $lineRepository,
        private readonly PostingService $postingService,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
        private readonly CheckService $checkService,
    ) {
    }

    public function check(FiscalYear $fiscalYear): CheckReport
    {
        return $this->checkService->check($fiscalYear);
    }

    /**
     * Income minus charges, from net balances. Positive is a profit.
     */
    public function computeResult(FiscalYear $fiscalYear): int
    {
        $jurisdiction = $this->jurisdictionRegistry->forLedger($fiscalYear->getLedger());
        $result = 0;

        foreach ($this->lineRepository->sumByAccount($fiscalYear->getLedger(), fiscalYear: $fiscalYear) as $number => $sums) {
            if (null !== $jurisdiction->isIncomeAccount((string) $number)) {
                $result += $sums['credit'] - $sums['debit'];
            }
        }

        return $result;
    }

    public function close(
        FiscalYear $fiscalYear,
        bool $force = false
    ): FiscalYear {
        if ($fiscalYear->isClosed()) {
            return $fiscalYear;
        }

        $previous = $this->fiscalYearRepository->findPrevious($fiscalYear);
        if ($previous && ! $previous->isClosed()) {
            throw new AccountingException(sprintf('Close fiscal year %s first.', $previous->getLabel()));
        }

        if (! $force) {
            $errors = $this->check($fiscalYear)->filter(Severity::Error);

            if (! $errors->isEmpty()) {
                throw new AccountingException(sprintf(
                    'Fiscal year %s cannot be closed: %s.',
                    $fiscalYear->getLabel(),
                    implode(', ', array_keys($errors->countByCode()))
                ));
            }
        }

        $result = $this->computeResult($fiscalYear);
        $next = $this->fiscalYearRepository->findNext($fiscalYear)
            ?? $this->fiscalYearService->createNext($fiscalYear->getLedger());

        $this->postOpening($fiscalYear, $next, $result);

        $fiscalYear
            ->setResult($result)
            ->setStatus(FiscalYearStatus::Closed)
            ->setDateClosed(new DateTimeImmutable());

        // Every entry of a closed year is final.
        foreach ($this->entryRepository->findBooked($fiscalYear) as $entry) {
            if (EntryStatus::Posted === $entry->getStatus()) {
                $entry->setStatus(EntryStatus::Validated)->setDateValidated(new DateTimeImmutable());
            }
        }

        $this->entityManager->flush();
        $this->eventDispatcher->dispatch(new FiscalYearClosedEvent($fiscalYear));

        return $fiscalYear;
    }

    /**
     * Reopens the last closed year, when the next one has not been closed:
     * its opening entries are reversed.
     */
    public function reopen(FiscalYear $fiscalYear): FiscalYear
    {
        $next = $this->fiscalYearRepository->findNext($fiscalYear);

        if ($next?->isClosed()) {
            throw new AccountingException('The following year is closed: reopen it first.');
        }

        if ($next) {
            foreach ($this->entryRepository->findBySource($fiscalYear->getLedger(), self::SOURCE_OPENING, (string) $fiscalYear->getId()) as $entry) {
                $this->postingService->reverse($entry, $entry->getDate(), 'Reopening '.$fiscalYear->getLabel());
            }
        }

        $fiscalYear->setStatus(FiscalYearStatus::Open)->setDateClosed(null)->setResult(null);
        $this->entityManager->flush();

        return $fiscalYear;
    }

    /**
     * Moves last year's result from the profit or loss account to retained
     * earnings or losses (or to any account the decision says).
     */
    public function appropriateResult(
        FiscalYear $closed,
        ?string $toAccount = null,
        ?DateTimeImmutable $date = null
    ): ?JournalEntry {
        $result = $closed->getResult();

        if (! $closed->isClosed() || null === $result || 0 === $result) {
            return null;
        }

        $next = $this->fiscalYearRepository->findNext($closed);
        $date ??= $next->getDateStart();
        $profit = $result > 0;

        $draft = (new EntryDraft(JournalType::Miscellaneous, $date, 'Appropriation of the result '.$closed->getLabel()))
            ->source(self::SOURCE_APPROPRIATION, (string) $closed->getId());

        if ($profit) {
            $draft->debit(AccountRole::ResultProfit, $result)->credit($toAccount ?? AccountRole::RetainedEarnings, $result);
        } else {
            $draft->credit(AccountRole::ResultLoss, -$result)->debit($toAccount ?? AccountRole::RetainedLosses, -$result);
        }

        return $this->postingService->postDraft($closed->getLedger(), $draft);
    }

    private function postOpening(
        FiscalYear $fiscalYear,
        FiscalYear $next,
        int $result
    ): ?JournalEntry {
        $ledger = $fiscalYear->getLedger();

        // An odd count: an opening is in place (each reopening adds a reversal).
        if (1 === count($this->entryRepository->findBySource($ledger, self::SOURCE_OPENING, (string) $fiscalYear->getId())) % 2) {
            return null;
        }

        $jurisdiction = $this->jurisdictionRegistry->forLedger($ledger);
        $draft = (new EntryDraft(JournalType::Opening, $next->getDateStart(), 'Opening balances '.$next->getLabel()))
            ->source(self::SOURCE_OPENING, (string) $fiscalYear->getId())
            ->piece('AN-'.$next->getLabel(), $next->getDateStart());

        /** @var array<string, array{number: string, party: ?Party, balance: int}> $balances */
        $balances = [];

        foreach ($this->lineRepository->findForAccount($ledger, fiscalYear: $fiscalYear) as $line) {
            $number = $line->getAccount()->getNumber();

            if (! $jurisdiction->isBalanceSheetAccount($number)) {
                continue;
            }

            $party = $line->getAccount()->isLettrable() ? $line->getParty() : null;
            $key = $number.'|'.$party?->getId();
            $balances[$key] ??= ['number' => $number, 'party' => $party, 'balance' => 0];
            $balances[$key]['balance'] += $line->getBalance();
        }

        foreach ($balances as $balance) {
            $draft->add($balance['number'], $balance['balance'], null, $balance['party']);
        }

        if ($result > 0) {
            $draft->credit(AccountRole::ResultProfit, $result);
        } elseif ($result < 0) {
            $draft->debit(AccountRole::ResultLoss, -$result);
        }

        if ($draft->isEmpty()) {
            return null;
        }

        return $this->postingService->postDraft($ledger, $draft);
    }
}
