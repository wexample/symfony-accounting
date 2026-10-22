<?php

namespace Wexample\SymfonyAccounting\Repository;

use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceTransmission;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method InvoiceTransmission|null find($id, $lockMode = null, $lockVersion = null)
 * @method InvoiceTransmission|null findOneBy(array $criteria, array $orderBy = null)
 * @method InvoiceTransmission[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class InvoiceTransmissionRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return InvoiceTransmission::class;
    }

    /**
     * @return list<InvoiceTransmission> The latest first.
     */
    public function findForInvoice(Invoice $invoice): array
    {
        return $this->findBy(['invoice' => $invoice], ['dateSent' => 'DESC']);
    }
}
