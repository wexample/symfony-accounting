<?php

namespace Wexample\SymfonyAccounting\Repository;

use DateTimeInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Entity\BankStatement;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

class BankStatementRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return BankStatement::class;
    }

    public function findLastOnOrBefore(
        BankAccount $bankAccount,
        DateTimeInterface $date
    ): ?BankStatement {
        return $this->createQueryBuilder('s')
            ->where('s.bankAccount = :account')
            ->andWhere('s.date <= :date')
            ->setParameter('account', $bankAccount->getId(), UuidType::NAME)
            ->setParameter('date', \DateTimeImmutable::createFromInterface($date)->setTime(0, 0), 'date_immutable')
            ->orderBy('s.date', self::SORT_DESC)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return BankStatement[] Oldest first.
     */
    public function findByBankAccount(BankAccount $bankAccount): array
    {
        return $this->findBy(['bankAccount' => $bankAccount], ['date' => self::SORT_ASC]);
    }

    public function findOneByDate(
        BankAccount $bankAccount,
        DateTimeInterface $date
    ): ?BankStatement {
        return $this->findOneBy(['bankAccount' => $bankAccount, 'date' => \DateTimeImmutable::createFromInterface($date)->setTime(0, 0)]);
    }
}
