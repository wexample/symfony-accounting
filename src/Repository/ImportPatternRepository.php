<?php

namespace Wexample\SymfonyAccounting\Repository;

use Wexample\SymfonyAccounting\Entity\ImportPattern;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method ImportPattern|null find($id, $lockMode = null, $lockVersion = null)
 * @method ImportPattern[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ImportPatternRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return ImportPattern::class;
    }
}
