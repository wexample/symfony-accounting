<?php

namespace Wexample\SymfonyAccounting\Enum;

enum InvoiceRelationType: string
{
    /** Quotation → the bill it became. */
    case Quotation = 'quotation';
    /** Bill → the credit note cancelling it, fully or partly. */
    case Credit = 'credit';
    /** Bill → the penalty billed for its late payment. */
    case Penalty = 'penalty';
    /** Purchase → the sale re-billing it (cooperatives, disbursements). */
    case Rebill = 'rebill';
    /** Deposit bill → the final bill deducting it. */
    case Deposit = 'deposit';
    /** Financing documents, never booked (#126). */
    case Financing = 'financing';

    /**
     * Whether people may link or unlink two documents this way by hand. The
     * others follow from an action (bill a quotation, credit, deposit,
     * penalty) and count in what is due: only that action sets them.
     */
    public function isManual(): bool
    {
        return in_array($this, [self::Rebill, self::Financing], true);
    }
}
