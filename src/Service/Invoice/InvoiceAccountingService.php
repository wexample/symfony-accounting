<?php

namespace Wexample\SymfonyAccounting\Service\Invoice;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Class\VatCode;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Enum\VatRole;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Interface\JurisdictionInterface;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;
use Wexample\SymfonyAccounting\Service\Ledger\PostingService;
use Wexample\SymfonyAccounting\Service\Vat\VatDeductionPolicy;
use Wexample\SymfonyMoney\Helper\RateHelper;

/**
 * Books documents in the ledger.
 *
 * A sale: the customer's account is debited with the total, revenue accounts are
 * credited with the bases, VAT accounts with the tax, one line per account and
 * VAT code. A purchase mirrors it. A credit note is booked on the opposite sides.
 * Self-assessed VAT (reverse charge, intra-EU acquisitions) is booked both as due
 * and as deductible.
 *
 * With VAT on payments, the tax goes to a pending account and becomes due when
 * the payment is booked (see AllocationAccountingService).
 */
class InvoiceAccountingService
{
    public const string SOURCE = 'invoice';
    public const string SOURCE_WRITE_OFF = 'invoice_write_off';

    public function __construct(
        private readonly PostingService $postingService,
        private readonly ChartService $chartService,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
        private readonly JournalEntryRepository $entryRepository,
        private readonly VatDeductionPolicy $deductionPolicy,
    ) {
    }

    public function findEntry(Invoice $invoice): ?JournalEntry
    {
        $entries = $this->entryRepository->findBySource($invoice->getLedger(), self::SOURCE, (string) $invoice->getId());

        return $entries[0] ?? null;
    }

    /**
     * Books the document once. Documents that are not accountable (quotations,
     * receipts, pro forma) are skipped.
     */
    public function book(Invoice $invoice): ?JournalEntry
    {
        if (! $invoice->getType()->isAccountable()) {
            return null;
        }

        if ($existing = $this->findEntry($invoice)) {
            return $existing;
        }

        return $this->postingService->postDraft($invoice->getLedger(), $this->buildDraft($invoice));
    }

    public function buildDraft(Invoice $invoice): EntryDraft
    {
        $ledger = $invoice->getLedger();
        $jurisdiction = $this->jurisdictionRegistry->forLedger($ledger);
        $sale = $invoice->isSale();
        // +1: the natural sides (sale: debit customer, credit revenue); −1 for credit notes.
        $side = $invoice->isCreditNote() ? -1 : 1;
        $label = trim(($invoice->getParty()?->getName() ?? '').' '.($invoice->getNumber() ?? '')) ?: 'Invoice';

        $draft = (new EntryDraft($sale ? JournalType::Sales : JournalType::Purchases, $invoice->getDateInvoice(), $label))
            ->piece($invoice->getNumber(), $invoice->getDateInvoice())
            ->source(self::SOURCE, (string) $invoice->getId());

        $bases = $this->computeBases($invoice);
        $breakdown = $invoice->calcPriceBreakdown();
        $total = 0;

        foreach ($bases as ['account' => $account, 'code' => $code, 'amount' => $amount]) {
            // Revenue is credited on a sale, expenses debited on a purchase.
            $draft->add($account, ($sale ? -1 : 1) * $side * $amount, null, null, $code, VatRole::Base);
            $total += $amount;
        }

        foreach ($this->groupBasesByCode($bases) as $codeValue => $base) {
            $code = $this->getVatCode($jurisdiction, $codeValue);

            if (! $code) {
                continue;
            }

            if (null === $code->accountRole) {
                if ($code->isCharged()) {
                    // Non-deductible VAT: part of the cost, on the code's main account.
                    $tax = $breakdown->getVatLine($code->rate)?->vat ?? RateHelper::rateOf($base, $code->rate);
                    $total += $tax;
                    $draft->add($this->mainAccountOf($bases, $code->code), ($sale ? -1 : 1) * $side * $tax, null, null, $code->code, VatRole::Base);
                }

                continue;
            }

            if ($code->isCharged()) {
                $tax = $breakdown->getVatLine($code->rate)?->vat ?? RateHelper::rateOf($base, $code->rate);
                $total += $tax;

                if (AccountRole::VatDeductible === $code->accountRole && $this->deductionPolicy->isPartial($ledger, (int) $invoice->getDateInvoice()->format('Y'))) {
                    $this->addPartiallyDeductible($draft, $invoice, $bases, $code->code, $base, $tax, $side, $jurisdiction, true);

                    continue;
                }

                // Deductible VAT on fixed assets is booked apart, in proportion of their base.
                $assetTax = AccountRole::VatDeductible === $code->accountRole
                    ? $this->assetShare($bases, $code->code, $base, $tax, $jurisdiction)
                    : 0;

                if (0 !== $assetTax) {
                    $draft->add(AccountRole::VatDeductibleAssets, $side * $assetTax, null, null, $code->code, VatRole::Tax);
                }

                $draft->add($this->taxRole($invoice, $code->accountRole), ($sale ? -1 : 1) * $side * ($tax - $assetTax), null, null, $code->code, VatRole::Tax);
            } elseif ($code->isSelfAssessed()) {
                $tax = RateHelper::rateOf($base, $code->rate);

                // Self-assessed VAT is due in full, and deductible like any other.
                if ($this->deductionPolicy->isPartial($ledger, (int) $invoice->getDateInvoice()->format('Y'))) {
                    $this->addPartiallyDeductible($draft, $invoice, $bases, $code->code, $base, $tax, $side, $jurisdiction, false);
                } else {
                    $draft->add($code->accountRole, $side * $tax, null, null, $code->code, VatRole::Tax);
                }

                $draft->add($code->selfAssessedRole, -$side * $tax, null, null, $code->code, VatRole::Tax);
            }
        }

        // An overridden total books its difference as rounding.
        $final = $invoice->calcPriceFinal();
        if ($final !== $total) {
            $difference = $final - $total;
            $gain = ($sale ? $difference > 0 : $difference < 0);
            $draft->add(
                $gain ? AccountRole::RoundingGain : AccountRole::RoundingLoss,
                ($sale ? -1 : 1) * $side * $difference
            );
        }

        $draft->add(
            $sale ? AccountRole::Customers : AccountRole::Suppliers,
            ($sale ? 1 : -1) * $side * $final,
            null,
            $invoice->getParty(),
            dateDue: $invoice->calcDateDue()
        );

        if (0 !== $draft->getBalance()) {
            throw new AccountingException(sprintf('Document %s does not balance (%d).', $invoice->getNumber(), $draft->getBalance()));
        }

        return $draft;
    }

    /**
     * Writes off what a customer will never pay: the base goes to bad debts and
     * the VAT back off the collected VAT, in proportion to the document's VAT.
     */
    public function writeOff(
        Invoice $invoice,
        int $amount,
        ?DateTimeImmutable $date = null
    ): JournalEntry {
        if (! $invoice->isSale() || ! $invoice->getStatus()->isEmitted()) {
            throw new AccountingException('Only an emitted sale can be written off.');
        }

        if ($amount <= 0 || $amount > $invoice->calcRemainingAmount()) {
            throw new AccountingException('The amount written off must be positive and at most what is left to pay.');
        }

        $date ??= new DateTimeImmutable();
        $jurisdiction = $this->jurisdictionRegistry->forLedger($invoice->getLedger());
        $draft = (new EntryDraft(JournalType::Miscellaneous, $date, 'Bad debt '.$invoice->getNumber()))
            ->piece($invoice->getNumber(), $invoice->getDateInvoice())
            ->source(self::SOURCE_WRITE_OFF, (string) $invoice->getId());

        $final = max(1, $invoice->calcPriceFinal());
        $taxes = [];
        foreach ($this->groupBasesByCode($this->computeBases($invoice)) as $codeValue => $base) {
            $code = $this->getVatCode($jurisdiction, $codeValue);
            if ($code?->isCharged()) {
                $taxes[$code->code] = $invoice->calcPriceBreakdown()->getVatLine($code->rate)?->vat ?? 0;
            }
        }

        $vatShares = RateHelper::allocate(
            RateHelper::mulDiv($amount, array_sum($taxes), $final),
            $taxes ?: [0]
        );
        $vatTotal = 0;

        foreach ($taxes as $codeValue => $tax) {
            $share = $vatShares[$codeValue];
            $vatTotal += $share;
            $draft->debit(AccountRole::VatCollected, $share, null, null, $codeValue, VatRole::Tax);
        }

        $draft
            ->debit(AccountRole::BadDebts, $amount - $vatTotal)
            ->credit(AccountRole::Customers, $amount, null, $invoice->getParty());

        $invoice->setPriceLoss($invoice->getPriceLoss() + $amount);

        return $this->postingService->postDraft($invoice->getLedger(), $draft);
    }

    /**
     * Cancels the booking of a document (when an emitted one is canceled).
     */
    public function reverse(
        Invoice $invoice,
        ?DateTimeImmutable $date = null
    ): ?JournalEntry {
        $entry = $this->findEntry($invoice);

        return $entry ? $this->postingService->reverse($entry, $date) : null;
    }

    /**
     * The base of each (account, VAT code), after the document discount, summing
     * exactly to the document's taxable base of each rate.
     *
     * @return list<array{account: string, code: ?string, amount: int}>
     */
    public function computeBases(Invoice $invoice): array
    {
        $breakdown = $invoice->calcPriceBreakdown();
        $itemsByRate = [];

        foreach ($invoice->getItems() as $item) {
            $itemsByRate[$item->getPriceVat()][] = $item;
        }

        $bases = [];

        foreach ($itemsByRate as $rate => $items) {
            $weights = array_map(fn (InvoiceItem $item) => $this->itemWeight($item), $items);
            $rateBase = $breakdown->getVatLine($rate)?->base ?? array_sum($weights);
            $shares = RateHelper::allocate($rateBase, $weights);

            foreach ($items as $index => $item) {
                $key = $this->resolveAccount($invoice, $item).'|'.$item->getVatCode();
                $bases[$key] ??= ['account' => $this->resolveAccount($invoice, $item), 'code' => $item->getVatCode(), 'amount' => 0];
                $bases[$key]['amount'] += $shares[$index];
            }
        }

        return array_values(array_filter($bases, fn (array $base) => 0 !== $base['amount']));
    }

    public function resolveAccount(
        Invoice $invoice,
        InvoiceItem $item
    ): string {
        $ledger = $invoice->getLedger();
        $explicit = $item->getAccountNumber()
            ?? $invoice->getAccountNumber()
            ?? $invoice->getParty()?->getDefaultAccountNumber();

        if ($explicit) {
            return $explicit;
        }

        if (InvoiceType::Penalty === $invoice->getType()) {
            return $this->chartService->getRoleNumber($ledger, AccountRole::LatePenaltiesIncome);
        }

        if (! $invoice->isSale()) {
            return $this->chartService->getRoleNumber($ledger, $item->isGoods() ? AccountRole::Purchases : AccountRole::PurchasesServices);
        }

        return $this->chartService->getRoleNumber($ledger, $item->isGoods() ? AccountRole::SalesGoods : AccountRole::SalesServices);
    }

    /**
     * @param list<array{account: string, code: ?string, amount: int}> $bases
     * @return array<string, int>
     */
    private function groupBasesByCode(array $bases): array
    {
        $grouped = [];

        foreach ($bases as $base) {
            if (null !== $base['code']) {
                $grouped[$base['code']] = ($grouped[$base['code']] ?? 0) + $base['amount'];
            }
        }

        return $grouped;
    }

    /**
     * @param list<array{account: string, code: ?string, amount: int}> $bases
     */
    /**
     * The part of $tax due to the fixed assets among the bases of $code.
     *
     * @param list<array{account: string, code: ?string, amount: int}> $bases
     */
    private function assetShare(
        array $bases,
        string $code,
        int $base,
        int $tax,
        JurisdictionInterface $jurisdiction
    ): int {
        $assetBase = 0;

        foreach ($bases as $line) {
            if ($line['code'] === $code && $jurisdiction->isFixedAssetAccount($line['account'])) {
                $assetBase += $line['amount'];
            }
        }

        return 0 === $base ? 0 : RateHelper::mulDiv($tax, $assetBase, $base);
    }

    /**
     * A purchase's VAT, charged or self-assessed, split along its bases: what the account's admission excludes
     * is a plain cost, what the year's coefficient leaves undeducted a cost tagged
     * for its regularization, the rest deductible — on fixed assets apart.
     *
     * @param list<array{account: string, code: ?string, amount: int}> $bases
     */
    private function addPartiallyDeductible(
        EntryDraft $draft,
        Invoice $invoice,
        array $bases,
        string $code,
        int $base,
        int $tax,
        int $side,
        JurisdictionInterface $jurisdiction,
        bool $charged
    ): void {
        $ledger = $invoice->getLedger();
        $coefficient = $this->deductionPolicy->getDeductionRate($ledger, (int) $invoice->getDateInvoice()->format('Y'));
        $lines = array_values(array_filter($bases, fn (array $line) => $line['code'] === $code));
        $left = $tax;
        $deductible = 0;
        $deductibleAssets = 0;

        foreach ($lines as $index => $line) {
            // The last line takes what rounding left, so the shares add up to the tax.
            $share = $index === count($lines) - 1 || 0 === $base ? $left : RateHelper::mulDiv($tax, $line['amount'], $base);
            $left -= $share;
            $admitted = RateHelper::mulDiv($share, $this->deductionPolicy->getAdmissionRate($ledger, $line['account']), RateHelper::BASIS);
            $deducted = RateHelper::mulDiv($admitted, $coefficient, RateHelper::BASIS);

            if (0 !== $share - $admitted) {
                $draft->add($line['account'], $side * ($share - $admitted));
            }

            if (0 !== $admitted - $deducted) {
                $draft->add($line['account'], $side * ($admitted - $deducted), null, null, $code, VatRole::NonDeductible);
            }

            if ($jurisdiction->isFixedAssetAccount($line['account'])) {
                $deductibleAssets += $deducted;
            } else {
                $deductible += $deducted;
            }
        }

        if (0 !== $deductibleAssets) {
            $draft->add(AccountRole::VatDeductibleAssets, $side * $deductibleAssets, null, null, $code, VatRole::Tax);
        }

        if (0 !== $deductible) {
            // Charged VAT waits for the payment under VAT on payments; self-assessed VAT never does.
            $draft->add($charged ? $this->taxRole($invoice, AccountRole::VatDeductible) : AccountRole::VatDeductible, $side * $deductible, null, null, $code, VatRole::Tax);
        }
    }

    private function mainAccountOf(
        array $bases,
        string $code
    ): string {
        $main = null;

        foreach ($bases as $base) {
            if ($base['code'] === $code && (null === $main || abs($base['amount']) > abs($main['amount']))) {
                $main = $base;
            }
        }

        return $main['account'];
    }

    private function itemWeight(InvoiceItem $item): int
    {
        $breakdown = $item->calcPriceBreakdown();

        if ($breakdown->isOverridden()) {
            return RateHelper::extractBase($breakdown->overridden, $item->getPriceVat());
        }

        return $breakdown->getNet();
    }

    private function getVatCode(
        JurisdictionInterface $jurisdiction,
        string $code
    ): ?VatCode {
        return $jurisdiction->getVatCode($code);
    }

    private function taxRole(
        Invoice $invoice,
        AccountRole $role
    ): AccountRole {
        if (! $invoice->getLedger()->isVatOnPayments()) {
            return $role;
        }

        return match ($role) {
            AccountRole::VatCollected => AccountRole::VatCollectedPending,
            AccountRole::VatDeductible => AccountRole::VatDeductiblePending,
            default => $role,
        };
    }
}
