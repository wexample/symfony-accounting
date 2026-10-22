<?php

namespace Wexample\SymfonyAccounting\Enum;

enum InvoiceType: string
{
    case Bill = 'bill';
    case CreditNote = 'credit_note';
    case Quotation = 'quotation';
    /** Late-payment penalties billed on top of a bill. */
    case Penalty = 'penalty';
    /** Acknowledges money received without being a bill (donations, deposits). */
    case Receipt = 'receipt';
    case ProForma = 'pro_forma';

    /**
     * Whether the document is booked in the ledger.
     */
    public function isAccountable(): bool
    {
        return in_array($this, [self::Bill, self::CreditNote, self::Penalty], true);
    }

    /**
     * Whether the document expects a payment.
     */
    public function isPayable(): bool
    {
        return in_array($this, [self::Bill, self::Penalty, self::Receipt, self::ProForma], true);
    }

    /**
     * The default numbering series code.
     */
    public function getSeriesCode(): string
    {
        return match ($this) {
            self::Bill => 'BIL',
            self::CreditNote => 'CRE',
            self::Quotation => 'QUO',
            self::Penalty => 'PEN',
            self::Receipt => 'REC',
            self::ProForma => 'PRO',
        };
    }
}
