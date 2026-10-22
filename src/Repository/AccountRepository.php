<?php

namespace Wexample\SymfonyAccounting\Repository;

use Wexample\SymfonyAccounting\Entity\Account;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method Account|null find($id, $lockMode = null, $lockVersion = null)
 * @method Account[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AccountRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return Account::class;
    }

    public function findOneByNumber(
        Ledger $ledger,
        string $number
    ): ?Account {
        return $this->findOneBy(['ledger' => $ledger, 'number' => $number]);
    }

    /**
     * @return Account[] Sorted by number.
     */
    public function findByLedger(Ledger $ledger): array
    {
        return $this->findBy(['ledger' => $ledger], ['number' => self::SORT_ASC]);
    }

    /**
     * @return array<string, Account> Keyed by number.
     */
    public function findIndexedByNumber(Ledger $ledger): array
    {
        $indexed = [];

        foreach ($this->findByLedger($ledger) as $account) {
            $indexed[$account->getNumber()] = $account;
        }

        return $indexed;
    }
}
