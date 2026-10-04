<?php

namespace Wexample\SymfonyAccounting\Service\Invoice;

use Symfony\Component\Uid\Uuid;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyPayment\Interface\PayableInterface;
use Wexample\SymfonyPayment\Interface\PayableResolverInterface;

/**
 * Lets symfony-payment collect invoices online: a payment of type "invoice"
 * resolves to its document.
 */
class InvoicePayableResolver implements PayableResolverInterface
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
    ) {
    }

    public function supports(string $payableType): bool
    {
        return Invoice::PAYABLE_TYPE === $payableType;
    }

    public function resolve(
        string $payableType,
        string $payableId
    ): ?PayableInterface {
        return Uuid::isValid($payableId) ? $this->invoiceRepository->find(Uuid::fromString($payableId)) : null;
    }
}
