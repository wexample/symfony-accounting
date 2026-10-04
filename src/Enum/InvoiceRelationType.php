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
}
