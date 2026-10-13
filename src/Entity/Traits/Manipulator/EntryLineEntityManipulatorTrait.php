<?php

namespace Wexample\SymfonyAccounting\Entity\Traits\Manipulator;

use Wexample\SymfonyAccounting\Entity\EntryLine;
use Wexample\SymfonyHelpers\Entity\Traits\Manipulator\EntityManipulatorTrait;

trait EntryLineEntityManipulatorTrait
{
    use EntityManipulatorTrait;

    public static function getEntityClassName(): string
    {
        return EntryLine::class;
    }
}
