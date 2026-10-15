<?php

namespace Wexample\SymfonyAccounting\Repository;

use DateTimeInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Wexample\SymfonyAccounting\Entity\Allocation;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method BankTransaction|null find($id, $lockMode = null, $lockVersion = null)
 * @method BankTransaction[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BankTransactionRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return BankTransaction::class;
    }

    /**
     * @param BankAccount|list<BankAccount> $bankAccount one account, or the shares of one
     */
    public function findOneByExternalId(
        BankAccount|array $bankAccount,
        string $externalId
    ): ?BankTransaction {
        return $this->findOneBy(['bankAccount' => $bankAccount, 'externalId' => $externalId]);
    }

    /**
     * @param BankAccount|list<BankAccount> $bankAccount one account, or the shares of one
     *
     * @return BankTransaction[]
     */
    public function findByFingerprint(
        BankAccount|array $bankAccount,
        string $fingerprint
    ): array {
        return $this->findBy(['bankAccount' => $bankAccount, 'fingerprint' => $fingerprint]);
    }

    /**
     * @return BankTransaction[] Oldest first.
     */
    public function findForPeriod(
        BankAccount $bankAccount,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null
    ): array {
        $builder = $this->createQueryBuilder('t')
            ->where('t.bankAccount = :account')
            ->setParameter('account', $bankAccount->getId(), UuidType::NAME)
            ->orderBy('t.date', self::SORT_ASC)
            ->addOrderBy('t.dateCreated', self::SORT_ASC);

        if ($from) {
            $builder->andWhere('t.date >= :from')->setParameter('from', \DateTimeImmutable::createFromInterface($from)->setTime(0, 0), 'date_immutable');
        }

        if ($to) {
            $builder->andWhere('t.date <= :to')->setParameter('to', \DateTimeImmutable::createFromInterface($to)->setTime(0, 0), 'date_immutable');
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * @param BankAccount|list<BankAccount> $bankAccount one account, or the shares of one
     */
    public function sumBetween(
        BankAccount|array $bankAccount,
        ?DateTimeInterface $after,
        DateTimeInterface $until
    ): int {
        $builder = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0)')
            ->andWhere('t.date <= :until')
            ->setParameter('until', \DateTimeImmutable::createFromInterface($until)->setTime(0, 0), 'date_immutable');

        $this->whereAccounts($builder, $bankAccount);

        if ($after) {
            $builder->andWhere('t.date > :after')->setParameter('after', \DateTimeImmutable::createFromInterface($after)->setTime(0, 0), 'date_immutable');
        }

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    /**
     * The latest lines of an account, or of all the shares of one, the newest first.
     *
     * @param BankAccount|list<BankAccount> $bankAccount
     *
     * @return BankTransaction[]
     */
    public function findLatest(
        BankAccount|array $bankAccount,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        int $limit = 300
    ): array {
        $builder = $this->createQueryBuilder('t')
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.dateCreated', 'DESC')
            ->setMaxResults($limit);
        $this->whereAccounts($builder, $bankAccount);

        if ($from) {
            $builder->andWhere('t.date >= :from')->setParameter('from', \DateTimeImmutable::createFromInterface($from)->setTime(0, 0), 'date_immutable');
        }

        if ($to) {
            $builder->andWhere('t.date < :to')->setParameter('to', \DateTimeImmutable::createFromInterface($to)->setTime(0, 0), 'date_immutable');
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * The lines of an account whose label holds the words, between the dates,
     * that are not explained yet: those that may be pointed elsewhere.
     *
     * @return BankTransaction[] Oldest first.
     */
    public function findPointable(
        BankAccount $bankAccount,
        string $words,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null
    ): array {
        $builder = $this->createQueryBuilder('t')
            ->where('t.bankAccount = :account')
            ->andWhere('LOWER(t.label) LIKE :words')
            ->andWhere('t.transferPeer IS NULL')
            ->andWhere('NOT EXISTS (SELECT 1 FROM '.BankTransaction::class.' s WHERE s.transferPeer = t)')
            ->andWhere('NOT EXISTS (SELECT 1 FROM '.Allocation::class.' x WHERE x.transaction = t AND x.status <> :excluded)')
            ->setParameter('account', $bankAccount->getId(), UuidType::NAME)
            ->setParameter('words', '%'.addcslashes(mb_strtolower($words), '%_').'%')
            ->setParameter('excluded', AllocationStatus::Excluded->value)
            ->orderBy('t.date', self::SORT_ASC);

        if ($from) {
            $builder->andWhere('t.date >= :from')->setParameter('from', \DateTimeImmutable::createFromInterface($from)->setTime(0, 0), 'date_immutable');
        }

        if ($to) {
            $builder->andWhere('t.date <= :to')->setParameter('to', \DateTimeImmutable::createFromInterface($to)->setTime(0, 0), 'date_immutable');
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * @param BankAccount|list<BankAccount> $bankAccount
     */
    private function whereAccounts(
        QueryBuilder $builder,
        BankAccount|array $bankAccount
    ): void {
        $conditions = [];

        foreach (is_array($bankAccount) ? $bankAccount : [$bankAccount] as $index => $account) {
            $conditions[] = 't.bankAccount = :account'.$index;
            $builder->setParameter('account'.$index, $account->getId(), UuidType::NAME);
        }

        $builder->andWhere('('.implode(' OR ', $conditions).')');
    }

    /**
     * Lines not fully explained yet (no transfer, allocations not covering them).
     *
     * @return BankTransaction[]
     */
    public function findUnsettled(
        Ledger $ledger,
        ?BankAccount $bankAccount = null,
        ?int $limit = null
    ): array {
        $builder = $this->unsettled($ledger, $bankAccount)->orderBy('t.date', self::SORT_ASC);

        if (null === $limit) {
            // All of them: their allocations with them, read once.
            $builder->leftJoin('t.allocations', 'a')->addSelect('a');
        } else {
            $builder->setMaxResults($limit);
        }

        return $builder->getQuery()->getResult();
    }

    public function countUnsettled(
        Ledger $ledger,
        ?BankAccount $bankAccount = null
    ): int {
        return (int) $this->unsettled($ledger, $bankAccount)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Neither half of a transfer, nor covered by its active allocations —
     * decided by the database, a person's books holding years of lines.
     */
    private function unsettled(
        Ledger $ledger,
        ?BankAccount $bankAccount
    ): QueryBuilder {
        $builder = $this->createQueryBuilder('t')
            ->join('t.bankAccount', 'b')
            ->where('b.ledger = :ledger')
            ->andWhere('t.transferPeer IS NULL')
            ->andWhere('NOT EXISTS (SELECT 1 FROM '.BankTransaction::class.' s WHERE s.transferPeer = t)')
            ->andWhere('t.amount <> (SELECT COALESCE(SUM(x.amount), 0) FROM '.Allocation::class.' x WHERE x.transaction = t AND x.status <> :excluded)')
            ->setParameter('ledger', $ledger->getId(), UuidType::NAME)
            ->setParameter('excluded', AllocationStatus::Excluded->value);

        if ($bankAccount) {
            $builder->andWhere('t.bankAccount = :account')->setParameter('account', $bankAccount->getId(), UuidType::NAME);
        }

        return $builder;
    }

    /**
     * Lines of the ledger's other accounts that could be the other half of a
     * transfer: opposite amount, close in time, not linked yet.
     *
     * @return BankTransaction[]
     */
    public function findTransferCandidates(
        BankTransaction $transaction,
        int $windowDays
    ): array {
        return $this->createQueryBuilder('t')
            ->join('t.bankAccount', 'b')
            ->leftJoin('t.allocations', 'a')
            ->addSelect('a')
            ->where('b.ledger = :ledger')
            ->andWhere('t.bankAccount != :account')
            ->andWhere('t.amount = :amount')
            ->andWhere('t.date BETWEEN :from AND :to')
            ->andWhere('t.transferPeer IS NULL')
            ->setParameter('ledger', $transaction->getLedger()->getId(), UuidType::NAME)
            ->setParameter('account', $transaction->getBankAccount()->getId(), UuidType::NAME)
            ->setParameter('amount', (string) -$transaction->getAmount())
            ->setParameter('from', $transaction->getDate()->modify('-'.$windowDays.' days'), 'date_immutable')
            ->setParameter('to', $transaction->getDate()->modify('+'.$windowDays.' days'), 'date_immutable')
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether another line points at this one as its transfer peer.
     */
    public function isTransferTarget(BankTransaction $transaction): bool
    {
        return null !== $this->findOneBy(['transferPeer' => $transaction]);
    }

    public function findTransferSource(BankTransaction $transaction): ?BankTransaction
    {
        return $this->findOneBy(['transferPeer' => $transaction]);
    }
}
