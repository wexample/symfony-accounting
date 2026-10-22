<?php

namespace Wexample\SymfonyAccounting\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;

class InvoiceStatusChangedEvent extends Event
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly InvoiceStatus $previousStatus,
    ) {
    }
}
