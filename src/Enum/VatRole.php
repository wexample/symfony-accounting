<?php

namespace Wexample\SymfonyAccounting\Enum;

/**
 * How an entry line takes part in a VAT return: as a taxable base, or as tax.
 */
enum VatRole: string
{
    case Base = 'base';

    case Tax = 'tax';

    /**
     * VAT paid that the deduction coefficient leaves undeducted, booked as a cost:
     * what the year's final coefficient regularizes.
     */
    case NonDeductible = 'non_deductible';
}
