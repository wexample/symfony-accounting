<?php

namespace Wexample\SymfonyAccounting\Enum;

enum InvoiceDirection: string
{
    /** Emitted by the ledger's company to a customer. */
    case Sale = 'sale';

    /** Received from a supplier. */
    case Purchase = 'purchase';

    /**
     * The sign a bank line paying this document carries: money in for sales.
     */
    public function getPaymentSign(): int
    {
        return self::Sale === $this ? 1 : -1;
    }
}
