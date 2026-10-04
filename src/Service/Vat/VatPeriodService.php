<?php

namespace Wexample\SymfonyAccounting\Service\Vat;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\VatPeriodicity;

/**
 * The VAT periods of a ledger: months, quarters or years, by its periodicity.
 */
class VatPeriodService
{
    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} First and last day.
     */
    public function getPeriodFor(
        Ledger $ledger,
        DateTimeImmutable $date
    ): array {
        $months = $ledger->getVatPeriodicity()->getMonths();
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $startMonth = intdiv($month - 1, $months) * $months + 1;
        $start = new DateTimeImmutable(sprintf('%d-%02d-01', $year, $startMonth));

        return [$start, $start->modify('+'.$months.' months -1 day')];
    }

    /**
     * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>
     */
    public function getPeriodsOfYear(
        Ledger $ledger,
        int $year
    ): array {
        if (VatPeriodicity::None === $ledger->getVatPeriodicity()) {
            return [];
        }

        $periods = [];
        $cursor = new DateTimeImmutable($year.'-01-01');

        while ((int) $cursor->format('Y') === $year) {
            $period = $this->getPeriodFor($ledger, $cursor);
            $periods[] = $period;
            $cursor = $period[1]->modify('+1 day');
        }

        return $periods;
    }
}
