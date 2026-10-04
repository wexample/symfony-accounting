<?php

namespace Wexample\SymfonyAccounting\Repository;

use DateTimeInterface;
use Wexample\SymfonyAccounting\Entity\FixedAsset;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method FixedAsset|null find($id, $lockMode = null, $lockVersion = null)
 * @method FixedAsset[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class FixedAssetRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return FixedAsset::class;
    }

    /**
     * Assets in service during at least part of the period.
     *
     * @return FixedAsset[]
     */
    public function findActiveDuring(
        Ledger $ledger,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): array {
        return array_values(array_filter(
            $this->findBy(['ledger' => $ledger], ['dateInService' => self::SORT_ASC]),
            fn (FixedAsset $asset) => $asset->getDateInService() <= $to
                && (null === $asset->getDateDisposed() || $asset->getDateDisposed() >= $from)
        ));
    }
}
