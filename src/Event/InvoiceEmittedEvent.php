<?php

namespace Wexample\SymfonyAccounting\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Wexample\SymfonyAccounting\Entity\Invoice;

/**
 * Dispatched once, when a document is emitted (sale) or recorded (purchase):
 * numbered and frozen. The ledger books it; the host renders its PDF and mails it.
 */
class InvoiceEmittedEvent extends Event
{
    public function __construct(public readonly Invoice $invoice)
    {
    }
}
