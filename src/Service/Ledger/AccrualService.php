<?php

namespace Wexample\SymfonyAccounting\Service\Ledger;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\JournalType;

/**
 * Period-end adjustments that undo themselves: an entry at the end of a year,
 * reversed on the first day of the next one (charges or income recorded in
 * advance, invoices to receive or to emit).
 */
class AccrualService
{
    public const string SOURCE = 'accrual';

    public function __construct(
        private readonly PostingService $postingService,
    ) {
    }

    /**
     * @return array{0: JournalEntry, 1: JournalEntry} The entry and its reversal.
     */
    public function postWithReversal(
        Ledger $ledger,
        EntryDraft $draft,
        DateTimeImmutable $reversalDate
    ): array {
        $draft->source(self::SOURCE, $draft->sourceId ?? bin2hex(random_bytes(8)));
        $entry = $this->postingService->postDraft($ledger, $draft);

        return [$entry, $this->postingService->reverse($entry, $reversalDate, 'Reversal: '.$entry->getLabel())];
    }

    /**
     * Part of a charge booked this year that belongs to the next one
     * (FR charges constatées d'avance, BE charges à reporter).
     *
     * @return array{0: JournalEntry, 1: JournalEntry}
     */
    public function prepaidExpense(
        Ledger $ledger,
        string $expenseAccount,
        int $amount,
        DateTimeImmutable $yearEnd,
        string $label
    ): array {
        return $this->postWithReversal(
            $ledger,
            (new EntryDraft(JournalType::Miscellaneous, $yearEnd, $label))
                ->debit(AccountRole::PrepaidExpenses, $amount)
                ->credit($expenseAccount, $amount),
            $yearEnd->modify('+1 day')
        );
    }

    /**
     * Part of an income booked this year that belongs to the next one
     * (FR produits constatés d'avance, BE produits à reporter).
     *
     * @return array{0: JournalEntry, 1: JournalEntry}
     */
    public function deferredIncome(
        Ledger $ledger,
        string $incomeAccount,
        int $amount,
        DateTimeImmutable $yearEnd,
        string $label
    ): array {
        return $this->postWithReversal(
            $ledger,
            (new EntryDraft(JournalType::Miscellaneous, $yearEnd, $label))
                ->debit($incomeAccount, $amount)
                ->credit(AccountRole::DeferredIncome, $amount),
            $yearEnd->modify('+1 day')
        );
    }
}
