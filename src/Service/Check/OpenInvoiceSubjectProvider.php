<?php

namespace Wexample\SymfonyAccounting\Service\Check;

use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyCheck\Interface\SubjectProviderInterface;

/**
 * `check:run invoices`: every emitted document not fully paid, of every ledger.
 */
class OpenInvoiceSubjectProvider implements SubjectProviderInterface
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
    ) {
    }

    public function getKey(): string
    {
        return 'invoices';
    }

    public function getSubjects(): iterable
    {
        return $this->invoiceRepository->findBy(['status' => [InvoiceStatus::Emitted, InvoiceStatus::PartiallyPaid]]);
    }
}
