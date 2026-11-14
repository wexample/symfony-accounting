<?php

namespace Wexample\SymfonyAccounting\Repository;

use DateTimeInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;
use Wexample\SymfonyPayment\Entity\Payment;

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

    /**
     * Emitted sale documents of a period of the given types, their party with
     * them: what counts toward a sales total, without hydrating the purchases,
     * drafts and quotations of the period.
     *
     * @param list<InvoiceType> $types
     * @return Invoice[]
     */
    public function findEmittedSalesForPeriod(
        Ledger $ledger,
        DateTimeInterface $from,
        DateTimeInterface $to,
        array $types
    ): array {
        $emitted = array_values(array_filter(InvoiceStatus::cases(), static fn (InvoiceStatus $status) => $status->isEmitted()));

        return $this->createQueryBuilder('i')
            ->addSelect('p')
            ->leftJoin('i.party', 'p')
            ->where('i.ledger = :ledger')
            ->andWhere('i.dateInvoice >= :from')
            ->andWhere('i.dateInvoice <= :to')
            ->andWhere('i.direction = :sale')
            ->andWhere('i.status IN (:statuses)')
            ->andWhere('i.type IN (:types)')
            ->setParameter('ledger', $ledger->getId(), UuidType::NAME)
            ->setParameter('from', \DateTimeImmutable::createFromInterface($from)->setTime(0, 0), 'date_immutable')
            ->setParameter('to', \DateTimeImmutable::createFromInterface($to)->setTime(0, 0), 'date_immutable')
            ->setParameter('sale', InvoiceDirection::Sale->value)
            ->setParameter('statuses', array_map(static fn (InvoiceStatus $status) => $status->value, $emitted))
            ->setParameter('types', array_map(static fn (InvoiceType $type) => $type->value, $types))
            ->orderBy('i.dateInvoice', self::SORT_ASC)
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether a document was already made from the model in the month of $date.
     */
    public function hasFromModelInMonth(
        Invoice $model,
        DateTimeInterface $date
    ): bool {
        $day = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);

        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.model = :model')
            ->andWhere('i.dateInvoice >= :from')
            ->andWhere('i.dateInvoice <= :to')
            ->setParameter('model', $model->getId(), UuidType::NAME)
            ->setParameter('from', $day->modify('first day of this month'), 'date_immutable')
            ->setParameter('to', $day->modify('last day of this month'), 'date_immutable')
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function findOneByNumber(
        Ledger $ledger,
        string $number
    ): ?Invoice {
        return $this->findOneBy(['ledger' => $ledger, 'number' => $number]);
    }

    /**
     * The bill a paid payable was invoiced with: none when it was not, or when
     * the payable is a document itself.
     */
    public function findBillForPayment(Payment $payment): ?Invoice
    {
        return $this->findOneBy(['payment' => $payment, 'type' => InvoiceType::Bill]);
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
