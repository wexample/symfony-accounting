<?php

namespace Wexample\SymfonyAccounting\Entity\Traits\Manipulator;

use Wexample\SymfonyAccounting\Entity\Journal;
use Wexample\SymfonyHelpers\Entity\Traits\Manipulator\EntityManipulatorTrait;

trait JournalEntityManipulatorTrait
{
    use EntityManipulatorTrait;

    public static function getEntityClassName(): string
    {
        return Journal::class;
    }
}
