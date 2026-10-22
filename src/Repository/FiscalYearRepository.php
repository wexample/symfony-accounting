<?php

namespace Wexample\SymfonyAccounting\Repository;

use DateTimeInterface;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method FiscalYear|null find($id, $lockMode = null, $lockVersion = null)
 */
class FiscalYearRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return FiscalYear::class;
    }

    public function findForDate(
        Ledger $ledger,
        DateTimeInterface $date
    ): ?FiscalYear {
        foreach ($this->findByLedger($ledger) as $fiscalYear) {
            if ($fiscalYear->contains($date)) {
                return $fiscalYear;
            }
        }

        return null;
    }

    /**
     * @return FiscalYear[] Oldest first.
     */
    public function findByLedger(Ledger $ledger): array
    {
        return $this->findBy(['ledger' => $ledger], ['dateStart' => self::SORT_ASC]);
    }

    public function findNext(FiscalYear $fiscalYear): ?FiscalYear
    {
        foreach ($this->findByLedger($fiscalYear->getLedger()) as $candidate) {
            if ($candidate->getDateStart() > $fiscalYear->getDateEnd()) {
                return $candidate;
            }
        }

        return null;
    }

    public function findPrevious(FiscalYear $fiscalYear): ?FiscalYear
    {
        $previous = null;

        foreach ($this->findByLedger($fiscalYear->getLedger()) as $candidate) {
            if ($candidate->getDateEnd() < $fiscalYear->getDateStart()) {
                $previous = $candidate;
            }
        }

        return $previous;
    }

    public function findLast(Ledger $ledger): ?FiscalYear
    {
        $years = $this->findByLedger($ledger);

        return end($years) ?: null;
    }
}
