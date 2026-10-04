<?php

namespace Wexample\SymfonyAccounting\Enum;

enum JournalType: string
{
    case Sales = 'sales';
    case Purchases = 'purchases';
    case Bank = 'bank';
    case Cash = 'cash';
    case Miscellaneous = 'miscellaneous';
    /** Opening balances carried from the previous fiscal year. */
    case Opening = 'opening';
}
