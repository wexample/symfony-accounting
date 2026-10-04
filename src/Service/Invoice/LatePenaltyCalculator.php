<?php

namespace Wexample\SymfonyAccounting\Service\Invoice;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\LatePenalty;
use Wexample\SymfonyAccounting\Class\LatePenaltyPolicy;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\InvoiceRelationType;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyMoney\Helper\RateHelper;

/**
 * Late-payment penalties of a bill, from the policy of the ledger's jurisdiction.
 *
 * The days late run from the due date to the payment date (today when unpaid).
 */
class LatePenaltyCalculator
{
    public function __construct(
        private readonly JurisdictionRegistry $jurisdictionRegistry,
        private readonly InvoiceFactory $invoiceFactory,
    ) {
    }

    public function calculate(
        Invoice $invoice,
        ?DateTimeImmutable $paidAt = null,
        ?LatePenaltyPolicy $policy = null
    ): ?LatePenalty {
        $policy ??= $this->jurisdictionRegistry->forLedger($invoice->getLedger())->getLatePenaltyPolicy($invoice->getLedger());
        $due = $invoice->calcDateDue()->modify('+'.$policy->graceDays.' days');
        $paidAt = ($paidAt ?? $invoice->getDatePaid() ?? new DateTimeImmutable())->setTime(0, 0);

        if ($paidAt <= $due) {
            return null;
        }

        $base = $invoice->calcAmountExpected();
        $days = (int) $due->diff($paidAt)->days;

        $lines = LatePenaltyPolicy::MODE_MONTHLY === $policy->mode
            ? $this->monthlyLines($due, $paidAt, $base, $policy->rate)
            : [[
                'label' => sprintf('%s → %s', $due->modify('+1 day')->format('Y-m-d'), $paidAt->format('Y-m-d')),
                'days' => $days,
                'amount' => RateHelper::mulDiv(RateHelper::rateOf($base, $policy->rate), $days, 365),
            ]];

        return new LatePenalty($days, $base, $lines, $policy->flatFee);
    }

    /**
     * A penalty document for a late bill, linked to it, not emitted yet.
     */
    public function createPenaltyInvoice(
        Invoice $bill,
        ?DateTimeImmutable $paidAt = null
    ): ?Invoice {
        if (InvoiceType::Bill !== $bill->getType() || InvoiceDirection::Sale !== $bill->getDirection()) {
            throw new AccountingException('Penalties are billed on sale bills.');
        }

        $penalty = $this->calculate($bill, $paidAt);

        if (! $penalty) {
            return null;
        }

        $invoice = $this->invoiceFactory->create(
            $bill->getLedger(),
            InvoiceType::Penalty,
            InvoiceDirection::Sale,
            $bill->getParty(),
            title: 'Late payment penalties '.$bill->getNumber()
        );

        foreach ($penalty->lines as $line) {
            $this->invoiceFactory->addItem($invoice, 'Late payment interest '.$line['label'], $line['amount'], vatRate: 0)
                ->setDuration($line['days'].'d');
        }

        if ($penalty->flatFee > 0) {
            $this->invoiceFactory->addItem($invoice, 'Flat recovery fee', $penalty->flatFee, vatRate: 0);
        }

        $this->invoiceFactory->link($bill, $invoice, InvoiceRelationType::Penalty);

        return $invoice;
    }

    /**
     * Network's rule: per month, base × monthly rate × days late in that month / days in it.
     */
    private function monthlyLines(
        DateTimeImmutable $due,
        DateTimeImmutable $paidAt,
        int $base,
        int $monthlyRate
    ): array {
        $lines = [];
        $cursor = $due->modify('+1 day');

        while ($cursor <= $paidAt) {
            $monthEnd = $cursor->modify('last day of this month');
            $end = $monthEnd < $paidAt ? $monthEnd : $paidAt;
            $days = (int) $cursor->diff($end)->days + 1;
            $daysInMonth = (int) $cursor->format('t');

            $lines[] = [
                'label' => sprintf('%s → %s', $cursor->format('Y-m-d'), $end->format('Y-m-d')),
                'days' => $days,
                'amount' => RateHelper::mulDiv(RateHelper::rateOf($base, $monthlyRate), $days, $daysInMonth),
            ];

            $cursor = $end->modify('+1 day');
        }

        return $lines;
    }
}
