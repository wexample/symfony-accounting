<?php

namespace Wexample\SymfonyAccounting\Repository;

use DateTimeInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Ledger;
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

    public function findOneByExternalId(
        BankAccount $bankAccount,
        string $externalId
    ): ?BankTransaction {
        return $this->findOneBy(['bankAccount' => $bankAccount, 'externalId' => $externalId]);
    }

    /**
     * @return BankTransaction[]
     */
    public function findByFingerprint(
        BankAccount $bankAccount,
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

    public function sumBetween(
        BankAccount $bankAccount,
        ?DateTimeInterface $after,
        DateTimeInterface $until
    ): int {
        $builder = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0)')
            ->where('t.bankAccount = :account')
            ->andWhere('t.date <= :until')
            ->setParameter('account', $bankAccount->getId(), UuidType::NAME)
            ->setParameter('until', \DateTimeImmutable::createFromInterface($until)->setTime(0, 0), 'date_immutable');

        if ($after) {
            $builder->andWhere('t.date > :after')->setParameter('after', \DateTimeImmutable::createFromInterface($after)->setTime(0, 0), 'date_immutable');
        }

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    /**
     * Lines not fully explained yet (no transfer, allocations not covering them).
     *
     * @return BankTransaction[]
     */
    public function findUnsettled(
        Ledger $ledger,
        ?BankAccount $bankAccount = null
    ): array {
        $builder = $this->createQueryBuilder('t')
            ->join('t.bankAccount', 'b')
            ->leftJoin('t.allocations', 'a')
            ->addSelect('a')
            ->where('b.ledger = :ledger')
            ->andWhere('t.transferPeer IS NULL')
            ->setParameter('ledger', $ledger->getId(), UuidType::NAME)
            ->orderBy('t.date', self::SORT_ASC);

        if ($bankAccount) {
            $builder->andWhere('t.bankAccount = :account')->setParameter('account', $bankAccount->getId(), UuidType::NAME);
        }

        return array_values(array_filter(
            $builder->getQuery()->getResult(),
            fn (BankTransaction $transaction) => ! $transaction->isSettled() && ! $this->isTransferTarget($transaction)
        ));
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
