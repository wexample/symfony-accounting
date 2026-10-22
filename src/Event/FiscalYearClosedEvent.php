<?php

namespace Wexample\SymfonyAccounting\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Wexample\SymfonyAccounting\Entity\FiscalYear;

class FiscalYearClosedEvent extends Event
{
    public function __construct(public readonly FiscalYear $fiscalYear)
    {
    }
}
