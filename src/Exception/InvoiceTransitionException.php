<?php

namespace Wexample\SymfonyAccounting\Exception;

use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;

class InvoiceTransitionException extends AccountingException
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly InvoiceStatus $to,
    ) {
        parent::__construct(sprintf(
            'A %s cannot go from "%s" to "%s".',
            $invoice->getType()->value,
            $invoice->getStatus()->value,
            $to->value
        ));
    }
}
