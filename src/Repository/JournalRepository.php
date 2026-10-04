<?php

namespace Wexample\SymfonyAccounting\Repository;

use Wexample\SymfonyAccounting\Entity\Journal;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method Journal|null find($id, $lockMode = null, $lockVersion = null)
 * @method Journal|null findOneBy(array $criteria, array $orderBy = null)
 * @method Journal[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class JournalRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return Journal::class;
    }

    public function findOneByCode(
        Ledger $ledger,
        string $code
    ): ?Journal {
        return $this->findOneBy(['ledger' => $ledger, 'code' => $code]);
    }

    public function findOneByType(
        Ledger $ledger,
        JournalType $type
    ): ?Journal {
        return $this->findOneBy(['ledger' => $ledger, 'type' => $type], ['code' => self::SORT_ASC]);
    }
}
