<?php

namespace Wexample\SymfonyAccounting\Repository;

use DateTimeInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method Invoice|null find($id, $lockMode = null, $lockVersion = null)
 * @method Invoice|null findOneBy(array $criteria, array $orderBy = null)
 * @method Invoice[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class InvoiceRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return Invoice::class;
    }

    /**
     * Emitted documents still expecting a payment.
     *
     * @return Invoice[]
     */
    public function findAwaitingPayment(
        Ledger $ledger,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null
    ): array {
        $builder = $this->createQueryBuilder('i')
            ->where('i.ledger = :ledger')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('ledger', $ledger->getId(), UuidType::NAME)
            ->setParameter('statuses', [InvoiceStatus::Emitted->value, InvoiceStatus::PartiallyPaid->value])
            ->orderBy('i.dateInvoice', self::SORT_DESC);

        if ($from) {
            $builder->andWhere('i.dateInvoice >= :from')->setParameter('from', \DateTimeImmutable::createFromInterface($from)->setTime(0, 0), 'date_immutable');
        }

        if ($to) {
            $builder->andWhere('i.dateInvoice <= :to')->setParameter('to', \DateTimeImmutable::createFromInterface($to)->setTime(0, 0), 'date_immutable');
        }

        return array_values(array_filter(
            $builder->getQuery()->getResult(),
            fn (Invoice $invoice) => $invoice->getType()->isPayable() || $invoice->isCreditNote()
        ));
    }

    public function findOneByNumber(
        Ledger $ledger,
        string $number
    ): ?Invoice {
        return $this->findOneBy(['ledger' => $ledger, 'number' => $number]);
    }

    public function findOneByPaymentReference(
        Ledger $ledger,
        string $reference
    ): ?Invoice {
        return $this->findOneBy(['ledger' => $ledger, 'paymentReference' => $reference]);
    }

    /**
     * @return Invoice[]
     */
    public function findForPeriod(
        Ledger $ledger,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): array {
        return $this->createQueryBuilder('i')
            ->where('i.ledger = :ledger')
            ->andWhere('i.dateInvoice >= :from')
            ->andWhere('i.dateInvoice <= :to')
            ->setParameter('ledger', $ledger->getId(), UuidType::NAME)
            ->setParameter('from', \DateTimeImmutable::createFromInterface($from)->setTime(0, 0), 'date_immutable')
            ->setParameter('to', \DateTimeImmutable::createFromInterface($to)->setTime(0, 0), 'date_immutable')
            ->orderBy('i.dateInvoice', self::SORT_ASC)
            ->getQuery()
            ->getResult();
    }
}
