<?php

namespace Wexample\SymfonyAccounting\Service\Invoice;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Entity\InvoiceRelation;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\InvoiceRelationType;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\InvoiceRelationRepository;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;
use Wexample\SymfonyMoney\Enum\PriceUnit;

/**
 * Creating documents and the documents that follow from others.
 */
class InvoiceFactory
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChartService $chartService,
        private readonly InvoiceRelationRepository $relationRepository,
    ) {
    }

    public function create(
        Ledger $ledger,
        InvoiceType $type = InvoiceType::Bill,
        InvoiceDirection $direction = InvoiceDirection::Sale,
        ?Party $party = null,
        ?DateTimeImmutable $dateInvoice = null,
        ?string $title = null,
    ): Invoice {
        $invoice = (new Invoice())
            ->setLedger($ledger)
            ->setType($type)
            ->setDirection($direction)
            ->setParty($party)
            ->setTitle($title)
            ->setCurrencyCode($ledger->getCurrencyCode());

        if ($dateInvoice) {
            $invoice->setDateInvoice($dateInvoice);
        }

        if (InvoiceType::Quotation === $type) {
            $invoice->setDateValidUntil($invoice->getDateInvoice()->modify('+'.$ledger->getSetting('quotation_validity_days', 30).' days'));
        }

        $this->entityManager->persist($invoice);

        return $invoice;
    }

    /**
     * @param int $unitPrice Before VAT, minor units.
     * @param int|string $quantity An int scaled by 100 (150 = 1.5), or a duration
     *                             string ("1h30", "2j") converted to days.
     * @param int|null $vatRate Basis points; the ledger's normal rate by default.
     */
    public function addItem(
        Invoice $invoice,
        string $title,
        int $unitPrice,
        int|string $quantity = InvoiceItem::QUANTITY_SCALE,
        ?int $vatRate = null,
        bool $goods = false,
        ?string $accountNumber = null,
    ): InvoiceItem {
        if (! $invoice->isEditable()) {
            throw new AccountingException('An emitted document cannot change: issue a credit note.');
        }

        $item = (new InvoiceItem())
            ->setTitle($title)
            ->setGoods($goods)
            ->setAccountNumber($accountNumber)
            ->setPriceRaw($unitPrice)
            ->setPriceVat($vatRate ?? (int) $invoice->getLedger()->getSetting('default_vat_rate', 0));

        if (is_string($quantity)) {
            $item->setDuration($quantity)->setUnit('day');
            $quantity = $this->durationToQuantity($invoice->getLedger(), $quantity);
        }

        $item->setQuantity($quantity);
        $invoice->addItem($item);
        $this->entityManager->persist($item);

        return $item;
    }

    /**
     * "1h30" → 21 (0.21 day at 7 h a day), "2j" → 200, "3" → 3 hours. Hours per day come from the
     * ledger setting `hours_per_day` (7 by default).
     */
    public function durationToQuantity(
        Ledger $ledger,
        string $duration
    ): int {
        $hoursPerDay = (int) $ledger->getSetting('hours_per_day', 7);
        $duration = strtolower(preg_replace('/\s/', '', $duration));
        preg_match_all('/(\d+(?:[.,]\d+)?)([jdhm]?)/', $duration, $matches, PREG_SET_ORDER);
        $minutes = 0.0;
        $previous = null;

        foreach ($matches as [, $value, $unit]) {
            $value = (float) str_replace(',', '.', $value);
            // A bare number continues the previous unit: "1h30" is 1 h 30 min, "2j4" 2 days 4 h.
            $unit = '' !== $unit ? $unit : match ($previous) {
                'h' => 'm',
                'j', 'd' => 'h',
                default => 'h',
            };
            $minutes += match ($unit) {
                'j', 'd' => $value * $hoursPerDay * 60,
                'm' => $value,
                default => $value * 60,
            };
            $previous = $unit;
        }

        return (int) round($minutes / ($hoursPerDay * 60) * InvoiceItem::QUANTITY_SCALE);
    }

    /**
     * A bill from an accepted quotation: same items, linked to it.
     */
    public function createBillFromQuotation(
        Invoice $quotation,
        ?DateTimeImmutable $dateInvoice = null
    ): Invoice {
        if (InvoiceType::Quotation !== $quotation->getType()) {
            throw new AccountingException('Only a quotation can become a bill.');
        }

        $bill = $this->duplicate($quotation, InvoiceType::Bill, $dateInvoice);
        $this->link($quotation, $bill, InvoiceRelationType::Quotation);
        $this->deductDeposits($quotation, $bill);

        return $bill;
    }

    /**
     * A quotation drafted from a bill (to re-propose the same work).
     */
    public function createQuotationFromBill(Invoice $bill): Invoice
    {
        return $this->duplicate($bill, InvoiceType::Quotation);
    }

    /**
     * A credit note cancelling a bill, fully (no amount), or partly: then one
     * item of `amount` before VAT, at the bill's main VAT rate.
     */
    public function createCreditNote(
        Invoice $bill,
        ?int $amount = null,
        ?string $reason = null,
        ?DateTimeImmutable $dateInvoice = null,
    ): Invoice {
        if (! in_array($bill->getType(), [InvoiceType::Bill, InvoiceType::Penalty], true) || ! $bill->getStatus()->isEmitted()) {
            throw new AccountingException('A credit note cancels an emitted bill.');
        }

        $credit = $this->create(
            $bill->getLedger(),
            InvoiceType::CreditNote,
            $bill->getDirection(),
            $bill->getParty(),
            $dateInvoice,
            $reason ?? 'Credit note for '.$bill->getNumber()
        );
        $credit->setAccountNumber($bill->getAccountNumber());

        if (null === $amount) {
            foreach ($bill->getItems() as $item) {
                $copy = $item->duplicate();
                $credit->addItem($copy);
                $this->entityManager->persist($copy);
            }

            $credit->setPriceDiscount($bill->getPriceDiscount(), $bill->getPriceDiscountUnit() ?? PriceUnit::Money);
        } else {
            $mainRate = array_key_last($bill->calcVatLines()) ?? 0;
            $this->addItem($credit, $reason ?? 'Credit', $amount, vatRate: $mainRate);
        }

        $this->link($bill, $credit, InvoiceRelationType::Credit, $amount);

        return $credit;
    }

    /**
     * A document from plain lines, e.g. a paid cart: each line has a title, a unit
     * price before VAT, a quantity (plain units) and a VAT rate.
     *
     * @param iterable<array{title: string, unitPrice: int, quantity?: int, vatRate?: int, goods?: bool, accountNumber?: ?string}> $lines
     */
    public function createFromLines(
        Ledger $ledger,
        Party $party,
        iterable $lines,
        InvoiceType $type = InvoiceType::Bill,
        InvoiceDirection $direction = InvoiceDirection::Sale,
        ?DateTimeImmutable $dateInvoice = null,
        ?string $title = null,
        string $origin = 'import',
    ): Invoice {
        $invoice = $this->create($ledger, $type, $direction, $party, $dateInvoice, $title)->setOrigin($origin);

        foreach ($lines as $line) {
            $this->addItem(
                $invoice,
                $line['title'],
                $line['unitPrice'],
                ($line['quantity'] ?? 1) * InvoiceItem::QUANTITY_SCALE,
                $line['vatRate'] ?? null,
                $line['goods'] ?? false,
                $line['accountNumber'] ?? null,
            );
        }

        return $invoice;
    }

    /**
     * A deposit bill (acompte) on a quotation or an order: one item of the amount
     * before VAT, booked on the customer advances account, at the source's main
     * VAT rate. The final bill deducts it (see createBillFromQuotation()).
     */
    public function createDepositBill(
        Invoice $source,
        int $amount,
        ?DateTimeImmutable $dateInvoice = null,
        ?string $title = null,
    ): Invoice {
        if ($amount <= 0) {
            throw new AccountingException('A deposit must be positive.');
        }

        $deposit = $this->create($source->getLedger(), InvoiceType::Bill, $source->getDirection(), $source->getParty(), $dateInvoice, $title ?? 'Deposit');
        $mainRate = array_key_last($source->calcVatLines()) ?? 0;

        $this->addItem(
            $deposit,
            $title ?? sprintf('Deposit on %s', $source->getNumber() ?? $source->getTitle() ?? 'order'),
            $amount,
            vatRate: $mainRate,
            accountNumber: $this->chartService->getRoleNumber($source->getLedger(), AccountRole::CustomerAdvances),
        );

        $this->link($source, $deposit, InvoiceRelationType::Deposit, $amount);

        return $deposit;
    }

    /**
     * Adds to a final bill one negative item per emitted deposit of its source,
     * on the customer advances account: the bill then asks only for the rest.
     */
    public function deductDeposits(
        Invoice $source,
        Invoice $finalBill
    ): void {
        foreach ($this->relationRepository->findBy(['source' => $source, 'type' => InvoiceRelationType::Deposit]) as $relation) {
            $deposit = $relation->getTarget();

            if (! $deposit->getStatus()->isEmitted()) {
                continue;
            }

            foreach ($deposit->getItems() as $item) {
                $deduction = $item->duplicate()
                    ->setTitle(sprintf('Deposit %s', $deposit->getNumber()));
                $deduction->setPriceRaw(-(int) $item->getPriceRaw());
                $finalBill->addItem($deduction);
                $this->entityManager->persist($deduction);
            }

            $this->link($deposit, $finalBill, InvoiceRelationType::Deposit);
        }

        $finalBill->updatePriceTotal();
    }

    /**
     * A copy of the document, as a new draft: content and items, never the
     * number, the dates, the payments nor the relations.
     */
    public function duplicate(
        Invoice $source,
        ?InvoiceType $type = null,
        ?DateTimeImmutable $dateInvoice = null,
    ): Invoice {
        $copy = $this->create(
            $source->getLedger(),
            $type ?? $source->getType(),
            $source->getDirection(),
            $source->getParty(),
            $dateInvoice,
            $source->getTitle()
        )
            ->setNote($source->getNote())
            ->setAccountNumber($source->getAccountNumber())
            ->setPaymentTermDays($source->getPaymentTermDays());

        if ($source->getPriceDiscount()) {
            $copy->setPriceDiscount($source->getPriceDiscount(), $source->getPriceDiscountUnit());
        }

        $this->copyItems($source, $copy);

        return $copy;
    }

    /**
     * A recurring document from its model: dated, with the model's service
     * period shifted to that date's month when the model has one.
     */
    public function createFromModel(
        Invoice $model,
        DateTimeImmutable $dateInvoice
    ): Invoice {
        if (InvoiceStatus::Model !== $model->getStatus()) {
            throw new AccountingException('This document is not a model.');
        }

        $invoice = $this->duplicate($model, dateInvoice: $dateInvoice)
            ->setModel($model)
            ->setOrigin('model');

        if ($model->getPeriodStart()) {
            $invoice->setPeriod(
                $dateInvoice->modify('first day of this month'),
                $dateInvoice->modify('last day of this month')
            );
        }

        return $invoice;
    }

    public function copyItems(
        Invoice $source,
        Invoice $target
    ): void {
        foreach ($source->getItems() as $item) {
            $copy = $item->duplicate();
            $target->addItem($copy);
            $this->entityManager->persist($copy);
        }

        $target->updatePriceTotal();
    }

    public function link(
        Invoice $source,
        Invoice $target,
        InvoiceRelationType $type,
        ?int $amount = null
    ): InvoiceRelation {
        $relation = (new InvoiceRelation())
            ->setSource($source)
            ->setTarget($target)
            ->setType($type)
            ->setAmount($amount);

        $this->entityManager->persist($relation);

        return $relation;
    }
}
