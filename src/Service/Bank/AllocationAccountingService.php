<?php

namespace Wexample\SymfonyAccounting\Service\Bank;

use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Entity\Allocation;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Enum\VatKind;
use Wexample\SymfonyAccounting\Enum\VatRole;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceAccountingService;
use Wexample\SymfonyAccounting\Service\Jurisdiction\AbstractJurisdiction;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyAccounting\Service\Ledger\PostingService;
use Wexample\SymfonyMoney\Helper\RateHelper;

/**
 * Books what moved on the bank, in the bank account's journal.
 *
 * - A payment: the bank account against the party's account, one entry per
 *   allocation (one counterpart line per payment, #286). With VAT on payments,
 *   the share of VAT paid moves from the pending account to the due one.
 * - A direct allocation: the bank account against the chosen account, VAT split out.
 * - A transfer: each side against the internal transfer account.
 */
class AllocationAccountingService
{
    public const string SOURCE_ALLOCATION = 'allocation';
    public const string SOURCE_TRANSFER = 'transfer';

    public function __construct(
        private readonly PostingService $postingService,
        private readonly JournalEntryRepository $entryRepository,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
        private readonly InvoiceAccountingService $invoiceAccountingService,
    ) {
    }

    public function findEntry(Allocation $allocation): ?JournalEntry
    {
        return $allocation->getEntry()
            ?? ($this->entryRepository->findBySource($allocation->getTransaction()->getLedger(), self::SOURCE_ALLOCATION, (string) $allocation->getId())[0] ?? null);
    }

    public function book(Allocation $allocation): JournalEntry
    {
        if ($existing = $this->findEntry($allocation)) {
            return $existing;
        }

        $transaction = $allocation->getTransaction();
        $bankAccount = $transaction->getBankAccount();
        $amount = $allocation->getAmount();
        $invoice = $allocation->getInvoice();

        $draft = (new EntryDraft($bankAccount->getJournalCode() ?? JournalType::Bank, $transaction->getDate(), $this->label($allocation)))
            ->piece($invoice?->getNumber() ?? $transaction->getExternalId(), $transaction->getDate())
            ->source(self::SOURCE_ALLOCATION, (string) $allocation->getId())
            ->add($bankAccount->getAccountNumber(), $amount);

        if ($invoice) {
            $draft->add(
                $invoice->isSale() ? AccountRole::Customers : AccountRole::Suppliers,
                -$amount,
                null,
                $invoice->getParty()
            );
            $this->addVatOnPayment($draft, $invoice, $allocation);
        } else {
            $this->addDirect($draft, $allocation);
        }

        $entry = $this->postingService->postDraft($transaction->getLedger(), $draft);
        $allocation->setEntry($entry);

        return $entry;
    }

    public function unbook(Allocation $allocation): ?JournalEntry
    {
        $entry = $this->findEntry($allocation);

        if (! $entry) {
            return null;
        }

        $allocation->setEntry(null);

        return $this->postingService->reverse($entry, $allocation->getTransaction()->getDate());
    }

    /**
     * @return list<JournalEntry>
     */
    public function bookTransfer(BankTransaction $outgoing): array
    {
        $incoming = $outgoing->getTransferPeer();
        $entries = [];

        foreach ([$outgoing, $incoming] as $line) {
            $existing = $this->entryRepository->findBySource($line->getLedger(), self::SOURCE_TRANSFER, (string) $line->getId());

            if ([] !== $existing) {
                $entries[] = $existing[0];

                continue;
            }

            $draft = (new EntryDraft($line->getBankAccount()->getJournalCode() ?? JournalType::Bank, $line->getDate(), 'Transfer '.$outgoing->getBankAccount()->getLabel().' → '.$incoming->getBankAccount()->getLabel()))
                ->piece($line->getExternalId(), $line->getDate())
                ->source(self::SOURCE_TRANSFER, (string) $line->getId())
                ->add($line->getBankAccount()->getAccountNumber(), $line->getAmount())
                ->add(AccountRole::InternalTransfer, -$line->getAmount());

            $entries[] = $this->postingService->postDraft($line->getLedger(), $draft);
        }

        return $entries;
    }

    public function unbookTransfer(
        BankTransaction $outgoing,
        BankTransaction $incoming
    ): void {
        foreach ([$outgoing, $incoming] as $line) {
            $entries = $this->entryRepository->findBySource($line->getLedger(), self::SOURCE_TRANSFER, (string) $line->getId());

            // An even count means every booking was already reversed.
            if (1 === count($entries) % 2) {
                $this->postingService->reverse($entries[0], $line->getDate());
            }
        }
    }

    /**
     * Cash-basis VAT: the share of the document's VAT this payment covers becomes due.
     */
    private function addVatOnPayment(
        EntryDraft $draft,
        Invoice $invoice,
        Allocation $allocation
    ): void {
        if (! $invoice->getLedger()->isVatOnPayments() || $invoice->calcPriceFinal() <= 0) {
            return;
        }

        $sale = $invoice->isSale();
        $side = $invoice->isCreditNote() ? -1 : 1;
        $paid = abs($allocation->getAmount());

        foreach ($invoice->calcVatLines() as $rate => $vatLine) {
            if (0 === $vatLine->vat) {
                continue;
            }

            $share = RateHelper::mulDiv($vatLine->vat, $paid, $invoice->calcPriceFinal());
            $code = $this->findChargedCode($invoice, $rate);

            if ($sale) {
                $draft->add(AccountRole::VatCollectedPending, $side * $share, null, null, $code, VatRole::Tax);
                $draft->add(AccountRole::VatCollected, -$side * $share, null, null, $code, VatRole::Tax);
            } else {
                $draft->add(AccountRole::VatDeductible, $side * $share, null, null, $code, VatRole::Tax);
                $draft->add(AccountRole::VatDeductiblePending, -$side * $share, null, null, $code, VatRole::Tax);
            }
        }
    }

    private function addDirect(
        EntryDraft $draft,
        Allocation $allocation
    ): void {
        $amount = $allocation->getAmount();
        $account = (string) $allocation->getAccountNumber();
        $rate = $allocation->getVatRate();

        if (0 === $rate) {
            $draft->add($account, -$amount, null, $allocation->getParty());

            return;
        }

        // Money out is a purchase, money in a sale.
        $direction = $amount < 0 ? InvoiceDirection::Purchase : InvoiceDirection::Sale;
        $jurisdiction = $this->jurisdictionRegistry->forLedger($allocation->getTransaction()->getLedger());
        $code = $jurisdiction instanceof AbstractJurisdiction
            ? $jurisdiction->buildVatCode($direction, VatKind::Domestic, $rate)->code
            : null;

        $base = RateHelper::extractBase(abs($amount), $rate);
        $vat = abs($amount) - $base;
        $sign = $amount < 0 ? 1 : -1;

        $draft->add($account, $sign * $base, null, $allocation->getParty(), $code, VatRole::Base);
        $draft->add(
            InvoiceDirection::Purchase === $direction ? AccountRole::VatDeductible : AccountRole::VatCollected,
            $sign * $vat,
            null,
            null,
            $code,
            VatRole::Tax
        );
    }

    private function findChargedCode(
        Invoice $invoice,
        int $rate
    ): ?string {
        foreach ($invoice->getItems() as $item) {
            if ($item->getPriceVat() === $rate && $item->getVatCode()) {
                return $item->getVatCode();
            }
        }

        return null;
    }

    private function label(Allocation $allocation): string
    {
        $transaction = $allocation->getTransaction();
        $invoice = $allocation->getInvoice();

        if ($invoice) {
            return trim('Payment '.($invoice->getNumber() ?? '').' '.($invoice->getParty()?->getName() ?? ''));
        }

        return $allocation->getLabel() ?? $transaction->getLabel();
    }
}
