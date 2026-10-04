<?php

namespace Wexample\SymfonyAccounting\Service\Ledger;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Exception\ClosedFiscalYearException;
use Wexample\SymfonyAccounting\Exception\NoFiscalYearException;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;

class FiscalYearService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FiscalYearRepository $fiscalYearRepository,
    ) {
    }

    /**
     * @param DateTimeImmutable|null $end Defaults to twelve months after the start.
     */
    public function create(
        Ledger $ledger,
        DateTimeImmutable $start,
        ?DateTimeImmutable $end = null
    ): FiscalYear {
        $end ??= $start->modify('+1 year -1 day');

        if ($end <= $start) {
            throw new AccountingException('A fiscal year must end after it starts.');
        }

        foreach ($this->fiscalYearRepository->findByLedger($ledger) as $existing) {
            if ($start <= $existing->getDateEnd() && $end >= $existing->getDateStart()) {
                throw new AccountingException(sprintf('The period overlaps fiscal year %s.', $existing->getLabel()));
            }
        }

        $fiscalYear = (new FiscalYear())
            ->setLedger($ledger)
            ->setDateStart($start)
            ->setDateEnd($end);

        $this->entityManager->persist($fiscalYear);
        $this->entityManager->flush();

        return $fiscalYear;
    }

    /**
     * The year following the last one, of the same length as a normal year.
     */
    public function createNext(Ledger $ledger): FiscalYear
    {
        $last = $this->fiscalYearRepository->findLast($ledger);

        if (! $last) {
            throw new AccountingException('The ledger has no fiscal year to follow.');
        }

        return $this->create($ledger, $last->getDateEnd()->modify('+1 day'));
    }

    public function getForDate(
        Ledger $ledger,
        DateTimeInterface $date
    ): FiscalYear {
        return $this->fiscalYearRepository->findForDate($ledger, $date)
            ?? throw new NoFiscalYearException($date);
    }

    /**
     * The open fiscal year covering the date.
     */
    public function getOpenForDate(
        Ledger $ledger,
        DateTimeInterface $date
    ): FiscalYear {
        $fiscalYear = $this->getForDate($ledger, $date);

        if ($fiscalYear->isClosed()) {
            throw new ClosedFiscalYearException($fiscalYear);
        }

        return $fiscalYear;
    }
}
