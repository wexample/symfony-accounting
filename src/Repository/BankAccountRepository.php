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

    /**
     * The shares of the same account at the bank — same owner, same IBAN —,
     * this one included, the owner's share first. Without an IBAN an account
     * is not shared.
     *
     * @return list<BankAccount>
     */
    public function findShares(BankAccount $bankAccount): array
    {
        if (null === $bankAccount->getIban()) {
            return [$bankAccount];
        }

        // Shares created in this unit of work, not written yet, count as well:
        // pointing many lines at once creates one share, not one per line.
        $pending = array_filter(
            $this->getEntityManager()->getUnitOfWork()->getScheduledEntityInsertions(),
            static fn (object $entity) => $entity instanceof BankAccount && $entity->getIban() === $bankAccount->getIban()
        );
        $candidates = [];

        foreach ([...$this->findBy(['iban' => $bankAccount->getIban()]), ...$pending] as $candidate) {
            $candidates[spl_object_id($candidate)] = $candidate;
        }

        $shares = array_values(array_filter(
            $candidates,
            static fn (BankAccount $share) => $share->getOwner() === $bankAccount->getOwner()
        ));
        usort($shares, static fn (BankAccount $a, BankAccount $b) => (int) $b->isOwnerShare() <=> (int) $a->isOwnerShare());

        return $shares ?: [$bankAccount];
    }

    /**
     * The share $ledger holds of the account, created when it has none yet:
     * the same account, booked on the ledger's own bank account number.
     * Persisted, not flushed.
     */
    public function findOrCreateShare(
        BankAccount $bankAccount,
        Ledger $ledger,
        string $accountNumber
    ): BankAccount {
        foreach ($this->findShares($bankAccount) as $share) {
            if ($share->getLedger() === $ledger) {
                return $share;
            }
        }

        $share = (new BankAccount())
            ->setLedger($ledger)
            ->setOwner($bankAccount->getOwner())
            ->setLabel($bankAccount->getLabel())
            ->setIban($bankAccount->getIban())
            ->setBic($bankAccount->getBic())
            ->setHolder($bankAccount->getHolder())
            ->setBankName($bankAccount->getBankName())
            ->setCurrencyCode($bankAccount->getCurrencyCode())
            ->setAccountNumber($accountNumber);
        $this->getEntityManager()->persist($share);

        return $share;
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
