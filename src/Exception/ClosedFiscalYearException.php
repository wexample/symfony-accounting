<?php

namespace Wexample\SymfonyAccounting\Exception;

use Wexample\SymfonyAccounting\Entity\FiscalYear;

class ClosedFiscalYearException extends AccountingException
{
    public function __construct(public readonly FiscalYear $fiscalYear)
    {
        parent::__construct(sprintf('Fiscal year %s is closed: its entries cannot change.', $fiscalYear->getLabel()));
    }
}
