<?php

namespace Wexample\SymfonyAccounting\Repository;

use Wexample\SymfonyAccounting\Entity\InvoiceRelation;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method InvoiceRelation|null find($id, $lockMode = null, $lockVersion = null)
 * @method InvoiceRelation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class InvoiceRelationRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return InvoiceRelation::class;
    }
}
