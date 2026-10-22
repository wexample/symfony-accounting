<?php

namespace Wexample\SymfonyAccounting\Exception;

class NoFiscalYearException extends AccountingException
{
    public function __construct(\DateTimeInterface $date)
    {
        parent::__construct(sprintf('No fiscal year covers %s.', $date->format('Y-m-d')));
    }
}
