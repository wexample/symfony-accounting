<?php

namespace Wexample\SymfonyAccounting\Enum;

/**
 * How an entry line takes part in a VAT return: as a taxable base, or as tax.
 */
enum VatRole: string
{
    case Base = 'base';

    case Tax = 'tax';
}
