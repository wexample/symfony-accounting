<?php

namespace Wexample\SymfonyAccounting\Entity\Traits\Manipulator;

use Wexample\SymfonyAccounting\Entity\InvoiceTransmission;
use Wexample\SymfonyHelpers\Entity\Traits\Manipulator\EntityManipulatorTrait;

trait InvoiceTransmissionEntityManipulatorTrait
{
    use EntityManipulatorTrait;

    public static function getEntityClassName(): string
    {
        return InvoiceTransmission::class;
    }
}
