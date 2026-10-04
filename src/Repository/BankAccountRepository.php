<?php

namespace Wexample\SymfonyAccounting\Repository;

use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method BankAccount|null find($id, $lockMode = null, $lockVersion = null)
 * @method BankAccount[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BankAccountRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return BankAccount::class;
    }

    public function findOneByIban(string $iban): ?BankAccount
    {
        return $this->findOneBy(['iban' => strtoupper(str_replace(' ', '', $iban))]);
    }

    /**
     * @return BankAccount[]
     */
    public function findWithProvider(?Ledger $ledger = null): array
    {
        $builder = $this->createQueryBuilder('b')
            ->where('b.provider IS NOT NULL')
            ->andWhere('b.active = true');

        $accounts = $builder->getQuery()->getResult();

        return null === $ledger
            ? $accounts
            : array_values(array_filter($accounts, fn (BankAccount $account) => $account->getLedger() === $ledger));
    }
}
