<?php

namespace Wexample\SymfonyAccounting\Enum;

enum EntryStatus: string
{
    /** Being prepared, not in the books yet: no number. */
    case Draft = 'draft';

    /** In the books, numbered; can still be corrected while the year is open. */
    case Posted = 'posted';

    /** Validated: final (FEC ValidDate). Corrected only by reversal. */
    case Validated = 'validated';
}
