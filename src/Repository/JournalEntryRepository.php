<?php

namespace Wexample\SymfonyAccounting\Repository;

use DateTimeInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\EntryStatus;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method JournalEntry|null find($id, $lockMode = null, $lockVersion = null)
 * @method JournalEntry[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class JournalEntryRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return JournalEntry::class;
    }

    public function findMaxNumber(FiscalYear $fiscalYear): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('MAX(e.number)')
            ->where('e.fiscalYear = :fiscalYear')
            ->setParameter('fiscalYear', $fiscalYear->getId(), UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Entries produced by a document, oldest first.
     *
     * @return JournalEntry[]
     */
    public function findBySource(
        Ledger $ledger,
        string $sourceType,
        string $sourceId
    ): array {
        return $this->findBy(
            ['ledger' => $ledger, 'sourceType' => $sourceType, 'sourceId' => $sourceId],
            ['dateCreated' => self::SORT_ASC]
        );
    }

    /**
     * Posted and validated entries of a fiscal year, in number order.
     *
     * @return JournalEntry[]
     */
    public function findBooked(FiscalYear $fiscalYear): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.fiscalYear = :fiscalYear')
            ->andWhere('e.status != :draft')
            ->setParameter('fiscalYear', $fiscalYear->getId(), UuidType::NAME)
            ->setParameter('draft', EntryStatus::Draft->value)
            ->orderBy('e.number', self::SORT_ASC)
            ->getQuery()
            ->getResult();
    }

    /**
     * The booked entries of a fiscal year with what an export reads of each —
     * its journal, its lines, their account and party — in one query rather
     * than one per entry. In number order, as findBooked(); by date then
     * number when $byDate (the chronological order of the FEC). Lines keep
     * their position, as the collection orders them.
     *
     * @return JournalEntry[]
     */
    public function findBookedWithLines(
        FiscalYear $fiscalYear,
        bool $byDate = false
    ): array {
        $builder = $this->createQueryBuilder('e')
            ->addSelect('j', 'l', 'a', 'p')
            ->join('e.journal', 'j')
            ->leftJoin('e.lines', 'l')
            ->leftJoin('l.account', 'a')
            ->leftJoin('l.party', 'p')
            ->where('e.fiscalYear = :fiscalYear')
            ->andWhere('e.status != :draft')
            ->setParameter('fiscalYear', $fiscalYear->getId(), UuidType::NAME)
            ->setParameter('draft', EntryStatus::Draft->value);

        if ($byDate) {
            $builder->orderBy('e.date', self::SORT_ASC)->addOrderBy('e.number', self::SORT_ASC);
        } else {
            $builder->orderBy('e.number', self::SORT_ASC);
        }

        return $builder
            ->addOrderBy('l.position', self::SORT_ASC)
            ->getQuery()
            ->getResult();
    }

    /**
     * Entries posted, not validated yet, dated up to a day (that day included).
     *
     * @return JournalEntry[]
     */
    public function findPostedUntil(
        Ledger $ledger,
        DateTimeInterface $until
    ): array {
        return $this->createQueryBuilder('e')
            ->where('e.ledger = :ledger')
            ->andWhere('e.status = :posted')
            ->andWhere('e.date <= :until')
            ->setParameter('ledger', $ledger->getId(), UuidType::NAME)
            ->setParameter('posted', EntryStatus::Posted->value)
            ->setParameter('until', \DateTimeImmutable::createFromInterface($until)->setTime(0, 0), 'date_immutable')
            ->getQuery()
            ->getResult();
    }

    public function countDrafts(FiscalYear $fiscalYear): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.fiscalYear = :fiscalYear')
            ->andWhere('e.status = :draft')
            ->setParameter('fiscalYear', $fiscalYear->getId(), UuidType::NAME)
            ->setParameter('draft', EntryStatus::Draft->value)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
