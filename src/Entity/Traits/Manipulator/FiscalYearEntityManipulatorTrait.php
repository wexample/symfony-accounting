<?php

namespace Wexample\SymfonyAccounting\Entity\Traits\Manipulator;

use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyHelpers\Entity\Traits\Manipulator\EntityManipulatorTrait;

trait FiscalYearEntityManipulatorTrait
{
    use EntityManipulatorTrait;

    public static function getEntityClassName(): string
    {
        return FiscalYear::class;
    }
}
