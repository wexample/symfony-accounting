<?php

namespace Wexample\SymfonyAccounting\Repository;

use DateTimeInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Wexample\SymfonyAccounting\Entity\Account;
use Wexample\SymfonyAccounting\Entity\EntryLine;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\EntryStatus;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * Only booked lines (entries posted or validated) count in balances and reports.
 */
class EntryLineRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return EntryLine::class;
    }

    public function queryBooked(Ledger $ledger): QueryBuilder
    {
        return $this->createQueryBuilder('l')
            ->join('l.entry', 'e')
            ->join('l.account', 'a')
            ->where('e.ledger = :ledger')
            ->andWhere('e.status != :draft')
            ->setParameter('ledger', $ledger->getId(), UuidType::NAME)
            ->setParameter('draft', EntryStatus::Draft->value);
    }

    /**
     * Debit and credit totals per account number, over a date range or a fiscal year.
     *
     * @return array<string, array{debit: int, credit: int}> Sorted by account number.
     */
    public function sumByAccount(
        Ledger $ledger,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        ?FiscalYear $fiscalYear = null,
    ): array {
        $builder = $this->queryBooked($ledger)
            ->select('a.number AS number, SUM(l.debit) AS debit, SUM(l.credit) AS credit')
            ->groupBy('a.number')
            ->orderBy('a.number', self::SORT_ASC);

        $this->filterPeriod($builder, $from, $to, $fiscalYear);
        $sums = [];

        foreach ($builder->getQuery()->getArrayResult() as $row) {
            $sums[(string) $row['number']] = ['debit' => (int) $row['debit'], 'credit' => (int) $row['credit']];
        }

        return $sums;
    }

    /**
     * @return EntryLine[] In date then entry number order.
     */
    public function findForAccount(
        Ledger $ledger,
        ?Account $account = null,
        ?Party $party = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        ?FiscalYear $fiscalYear = null,
        ?string $accountPrefix = null,
    ): array {
        $builder = $this->queryBooked($ledger)
            ->addSelect('e', 'a')
            ->orderBy('a.number', self::SORT_ASC)
            ->addOrderBy('e.date', self::SORT_ASC)
            ->addOrderBy('e.number', self::SORT_ASC);

        if ($account) {
            $builder->andWhere('l.account = :account')->setParameter('account', $account->getId(), UuidType::NAME);
        }

        if ($party) {
            $builder->andWhere('l.party = :party')->setParameter('party', $party->getId(), UuidType::NAME);
        }

        if ($accountPrefix) {
            $builder->andWhere('a.number LIKE :prefix')->setParameter('prefix', $accountPrefix.'%');
        }

        $this->filterPeriod($builder, $from, $to, $fiscalYear);

        return $builder->getQuery()->getResult();
    }

    /**
     * Lines tagged with a VAT code, over a period.
     *
     * @return EntryLine[]
     */
    public function findVatLines(
        Ledger $ledger,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): array {
        $builder = $this->queryBooked($ledger)
            ->addSelect('e', 'a')
            ->andWhere('l.vatCode IS NOT NULL');

        $this->filterPeriod($builder, $from, $to);

        return $builder->getQuery()->getResult();
    }

    private function filterPeriod(
        QueryBuilder $builder,
        ?DateTimeInterface $from,
        ?DateTimeInterface $to,
        ?FiscalYear $fiscalYear = null,
    ): void {
        if ($fiscalYear) {
            $builder->andWhere('e.fiscalYear = :fiscalYear')->setParameter('fiscalYear', $fiscalYear->getId(), UuidType::NAME);
        }

        if ($from) {
            $builder->andWhere('e.date >= :from')->setParameter('from', \DateTimeImmutable::createFromInterface($from)->setTime(0, 0), 'date_immutable');
        }

        if ($to) {
            $builder->andWhere('e.date <= :to')->setParameter('to', \DateTimeImmutable::createFromInterface($to)->setTime(0, 0), 'date_immutable');
        }
    }
}
