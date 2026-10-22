<?php

namespace Wexample\SymfonyAccounting\Repository;

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
