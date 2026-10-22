<?php

namespace Wexample\SymfonyAccounting\Enum;

enum FiscalYearStatus: string
{
    case Open = 'open';

    /** Locked: no entry can be added, changed or removed. */
    case Closed = 'closed';
}
