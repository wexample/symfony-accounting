<?php

namespace Wexample\SymfonyAccounting\Checker;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceAccountingService;
use Wexample\SymfonyCheck\Class\Finding;
use Wexample\SymfonyCheck\Interface\CheckerInterface;

/**
 * The consistency warnings of a document: what network showed on each invoice,
 * and what experience says goes wrong (missing files, payments not matching).
 * Hosts add their own rules with more checkers on Invoice.
 */
class InvoiceChecker implements CheckerInterface
{
    public const string DOMAIN = 'accounting_check';

    public function __construct(
        private readonly InvoiceAccountingService $accountingService,
        private readonly ?DateTimeImmutable $now = null,
    ) {
    }

    public function supports(object $subject): bool
    {
        return $subject instanceof Invoice;
    }

    /**
     * @param Invoice $subject
     */
    public function check(object $subject): iterable
    {
        $invoice = $subject;
        $status = $invoice->getStatus();
        $now = ($this->now ?? new DateTimeImmutable())->setTime(0, 0);
        $emitted = $status->isEmitted();

        if ($invoice->calcPriceFinal() < 0) {
            yield $this->error('invoice.negative_total');
        }

        if (! $emitted) {
            if ($invoice->calcPaidAmount() > 0 && InvoiceStatus::Model !== $status) {
                yield $this->error('invoice.payment_on_wrong_status', ['status' => $status->value]);
            }

            return;
        }

        if (! $invoice->getNumber()) {
            yield $this->error('invoice.missing_number');
        }

        if (! $invoice->getParty()) {
            yield $this->error('invoice.missing_party');
        }

        if (! $invoice->getDocumentPath()) {
            yield $invoice->isSale()
                ? Finding::info('invoice.missing_document', [], self::DOMAIN)
                : $this->warning('invoice.missing_document');
        }

        if (0 === $invoice->calcPriceFinal()) {
            yield $this->warning('invoice.zero_total');
        }

        $this->checkVat($invoice, $findings);
        yield from $findings;

        if ($invoice->getType()->isAccountable() && ! $this->accountingService->findEntry($invoice)) {
            yield $this->error('invoice.not_booked');
        }

        if (InvoiceType::Quotation === $invoice->getType()) {
            if (InvoiceStatus::Emitted === $status && $invoice->getDateValidUntil() && $invoice->getDateValidUntil() < $now) {
                yield Finding::info('invoice.quotation_expired', ['date' => $invoice->getDateValidUntil()->format('Y-m-d')], self::DOMAIN);
            }

            return;
        }

        yield from $this->checkPayments($invoice, $now);
    }

    private function checkPayments(
        Invoice $invoice,
        DateTimeImmutable $now
    ): iterable {
        $expected = $invoice->calcAmountExpected();
        $paid = $invoice->calcPaidAmount();
        $status = $invoice->getStatus();

        if ($paid > $expected) {
            yield $this->error('invoice.overpaid', ['excess' => $paid - $expected]);
        }

        if ($paid >= $expected && $expected > 0 && InvoiceStatus::Paid !== $status) {
            yield $this->warning('invoice.paid_but_status_not_paid');
        }

        if (InvoiceStatus::Paid === $status && $paid < $expected) {
            yield $this->error('invoice.status_paid_but_unpaid', ['remaining' => $expected - $paid]);
        }

        if ($paid > 0 && $paid < $expected) {
            yield Finding::info('invoice.partially_paid', ['paid' => $paid, 'remaining' => $expected - $paid], self::DOMAIN);
        }

        $pending = 0;
        foreach ($invoice->getAllocations() as $allocation) {
            if (AllocationStatus::Pending === $allocation->getStatus()) {
                ++$pending;
            }
        }

        if ($pending > 0) {
            yield $this->warning('invoice.payment_to_validate', ['count' => $pending]);
        }

        if ($paid < $expected && $invoice->calcDateDue() < $now) {
            $days = (int) $invoice->calcDateDue()->diff($now)->days;

            yield $invoice->isCreditNote()
                ? $this->warning('invoice.credit_not_consumed', ['days' => $days])
                : $this->warning('invoice.overdue', ['days' => $days, 'remaining' => $expected - $paid]);
        }
    }

    private function checkVat(
        Invoice $invoice,
        ?array &$findings
    ): void {
        $findings = [];
        $vatSubject = $invoice->getLedger()->isVatSubject();

        foreach ($invoice->getItems() as $item) {
            if (null === $item->getVatCode()) {
                $findings[] = $this->warning('invoice.item_without_vat_code', ['item' => $item->getTitle()]);
            }

            if ($invoice->isSale() && ! $vatSubject && $item->getPriceVat() > 0) {
                $findings[] = $this->error('invoice.vat_without_vat_subject', ['item' => $item->getTitle()]);
            }
        }
    }

    private function error(
        string $code,
        array $parameters = []
    ): Finding {
        return Finding::error($code, $parameters, self::DOMAIN);
    }

    private function warning(
        string $code,
        array $parameters = []
    ): Finding {
        return Finding::warning($code, $parameters, self::DOMAIN);
    }
}
