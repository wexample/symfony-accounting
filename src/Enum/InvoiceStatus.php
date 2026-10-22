<?php

namespace Wexample\SymfonyAccounting\Enum;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    /** Submitted for approval. */
    case Waiting = 'waiting';
    case Approved = 'approved';
    /** Emitted to the customer (sale) or recorded (purchase): numbered and frozen. */
    case Emitted = 'emitted';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Canceled = 'canceled';
    /** Quotation accepted by the customer. */
    case Accepted = 'accepted';
    /** Quotation refused by the customer. */
    case Refused = 'refused';
    /** Template for recurring invoices; never emitted itself. */
    case Model = 'model';

    /**
     * Items and amounts can still change.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Waiting, self::Approved, self::Model], true);
    }

    public function isEmitted(): bool
    {
        return in_array($this, [self::Emitted, self::PartiallyPaid, self::Paid, self::Accepted, self::Refused], true);
    }
}
