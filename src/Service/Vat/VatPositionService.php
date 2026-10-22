<?php

namespace Wexample\SymfonyAccounting\Service\Vat;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\VatPosition;
use Wexample\SymfonyAccounting\Class\VatPositionPeriod;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\VatPeriodicity;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;

/**
 * The VAT position of a calendar year, read from the entries: one column per
 * return period — months, quarters, or half-years for an annual regime, whose
 * deposits fall in July and December —, deposits paid as booked, and those
 * the jurisdiction expects from last year's net VAT.
 */
class VatPositionService
{
    public function __construct(
        private readonly VatReturnService $vatReturnService,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
    ) {
    }

    public function build(
        Ledger $ledger,
        int $year
    ): VatPosition {
        $months = match ($ledger->getVatPeriodicity()) {
            VatPeriodicity::Monthly => 1,
            VatPeriodicity::Quarterly => 3,
            VatPeriodicity::Annual => 6,
            default => 12,
        };
        $start = new DateTimeImmutable($year.'-01-01');
        $previous = $this->vatReturnService->compute($ledger, $start->modify('-1 year'), $start->modify('-1 day'));
        $schedule = $this->jurisdictionRegistry->forLedger($ledger)
            ->getVatDepositSchedule($ledger, $year, $previous->getDue() - $previous->getDeductible());
        $periods = [];
        $previousCredit = 0;

        for ($from = $start; (int) $from->format('Y') === $year; $from = $from->modify('+'.$months.' months')) {
            $to = $from->modify('+'.$months.' months -1 day');
            $vatReturn = $this->vatReturnService->compute($ledger, $from, $to);
            $previousCredit = [] === $periods ? $vatReturn->previousCredit : $previousCredit;

            $periods[] = new VatPositionPeriod(
                $from,
                $to,
                $vatReturn->getDue(),
                $vatReturn->getDeductible(),
                $vatReturn->getDeductibleAssets(),
                $vatReturn->deposits,
                array_sum(array_map(
                    fn (array $deposit) => $deposit['amount'],
                    array_filter($schedule, fn (array $deposit) => $deposit['date'] >= $from && $deposit['date'] <= $to)
                )),
            );
        }

        return new VatPosition($year, $periods, $previousCredit);
    }
}
