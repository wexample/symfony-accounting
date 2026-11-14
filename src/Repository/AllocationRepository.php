<?php

namespace Wexample\SymfonyAccounting\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Wexample\SymfonyAccounting\Entity\Allocation;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method Allocation|null find($id, $lockMode = null, $lockVersion = null)
 * @method Allocation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AllocationRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return Allocation::class;
    }

    /**
     * Allocations by id, in one query.
     *
     * @param list<Uuid> $ids
     * @return Allocation[]
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        // Ids as the database stores them: binary on some platforms, text on others.
        $type = Type::getType(UuidType::NAME);
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();

        return $this->createQueryBuilder('a')
            ->where('a.id IN (:ids)')
            ->setParameter(
                'ids',
                array_map(static fn (Uuid $id) => $type->convertToDatabaseValue($id, $platform), $ids),
                match ($type->getBindingType()) {
                    ParameterType::BINARY, ParameterType::LARGE_OBJECT => ArrayParameterType::BINARY,
                    default => ArrayParameterType::STRING,
                }
            )
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether someone refused this pairing before: matchers must not propose it again.
     */
    public function isExcluded(
        BankTransaction $transaction,
        ?Invoice $invoice = null,
        ?string $accountNumber = null
    ): bool {
        $criteria = ['transaction' => $transaction, 'status' => AllocationStatus::Excluded];

        if ($invoice) {
            $criteria['invoice'] = $invoice;
        } else {
            $criteria['accountNumber'] = $accountNumber;
        }

        return null !== $this->findOneBy($criteria);
    }
}
