<?php

namespace Wexample\SymfonyAccounting\Entity\Traits\Manipulator;

use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyHelpers\Entity\Traits\Manipulator\EntityManipulatorTrait;

trait PartyEntityManipulatorTrait
{
    use EntityManipulatorTrait;

    public static function getEntityClassName(): string
    {
        return Party::class;
    }
}
