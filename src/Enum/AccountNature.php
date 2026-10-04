<?php

namespace Wexample\SymfonyAccounting\Enum;

/**
 * The side an account's balance normally sits on.
 */
enum AccountNature: string
{
    case Debit = 'debit';

    case Credit = 'credit';

    /** Either, depending on the balance (bank, VAT, current accounts). */
    case Both = 'both';
}
