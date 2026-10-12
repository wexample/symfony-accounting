<?php

namespace Wexample\SymfonyAccounting\Service\Ledger;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyMoney\Service\ExchangeRateService;

/**
 * At closing, what is still owed in another currency valued at the day's rate:
 * the gap with its booked value goes to an unrealized exchange gain or loss, and
 * the next day reverses it, so that the payment books the realized difference
 * against the original value as usual.
 */
class ExchangeRevaluationService
{
    public const string SOURCE = 'exchange_revaluation';

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly AccrualService $accrualService,
        private readonly JournalEntryRepository $entryRepository,
    ) {
    }

    /**
     * Each open document in another currency, what is left of it, its booked
     * value and its value at the day's rate, in the ledger's currency.
     *
     * @return list<array{invoice: Invoice, remaining: int, booked: int, current: int}>
     */
    public function compute(
        Ledger $ledger,
        DateTimeImmutable $date
    ): array {
        $lines = [];

        foreach ($this->invoiceRepository->findAwaitingPayment($ledger, to: $date) as $invoice) {
            if (! $invoice->isForeignCurrency() || 0 === $remaining = $invoice->calcRemainingAmount()) {
                continue;
            }

            $rate = $this->exchangeRateService->getRate($invoice->getCurrencyCode(), $ledger->getCurrencyCode(), $date)
                ?? throw new AccountingException(sprintf('No exchange rate from %s to %s on %s.', $invoice->getCurrencyCode(), $ledger->getCurrencyCode(), $date->format('Y-m-d')));

            $lines[] = [
                'invoice' => $invoice,
                'remaining' => $remaining,
                'booked' => $invoice->getLedgerExchangeRate()->convert($remaining),
                'current' => $rate->convert($remaining),
            ];
        }

        return $lines;
    }

    /**
     * Posts the revaluation on $date and its reversal the next day; null when
     * nothing moved.
     *
     * @return array{0: JournalEntry, 1: JournalEntry}|null
     */
    public function revalue(
        Ledger $ledger,
        DateTimeImmutable $date
    ): ?array {
        $sourceId = self::SOURCE.':'.$date->format('Y-m-d');

        if ([] !== $this->entryRepository->findBySource($ledger, AccrualService::SOURCE, $sourceId)) {
            throw new AccountingException(sprintf('The exchange revaluation of %s is already posted.', $date->format('Y-m-d')));
        }

        $draft = (new EntryDraft(JournalType::Miscellaneous, $date, 'Exchange revaluation '.$date->format('Y-m-d')))
            ->source(AccrualService::SOURCE, $sourceId);

        foreach ($this->compute($ledger, $date) as ['invoice' => $invoice, 'booked' => $booked, 'current' => $current]) {
            // A sale is owed to us: more is a gain. A purchase is owed by us: more is a loss.
            $change = ($invoice->isSale() ? 1 : -1) * ($invoice->isCreditNote() ? -1 : 1) * ($current - $booked);

            if (0 === $change) {
                continue;
            }

            // The same sign moves the customer or supplier account the right way.
            $draft->add($invoice->isSale() ? AccountRole::Customers : AccountRole::Suppliers, $change, null, $invoice->getParty());
            $draft->add($change > 0 ? AccountRole::UnrealizedExchangeGain : AccountRole::UnrealizedExchangeLoss, -$change);
        }

        if ($draft->isEmpty()) {
            return null;
        }

        return $this->accrualService->postWithReversal($ledger, $draft, $date->modify('+1 day'));
    }
}
