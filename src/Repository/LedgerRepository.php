<?php

namespace Wexample\SymfonyAccounting\Repository;

use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method Ledger|null find($id, $lockMode = null, $lockVersion = null)
 * @method Ledger|null findOneBy(array $criteria, array $orderBy = null)
 * @method Ledger[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class LedgerRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return Ledger::class;
    }

    public function findOneByExternalReference(string $externalReference): ?Ledger
    {
        return $this->findOneBy(['externalReference' => $externalReference]);
    }
}
