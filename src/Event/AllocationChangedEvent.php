<?php

namespace Wexample\SymfonyAccounting\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Wexample\SymfonyAccounting\Entity\Allocation;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;

/**
 * Dispatched when an allocation is created or changes status.
 */
class AllocationChangedEvent extends Event
{
    public function __construct(
        public readonly Allocation $allocation,
        public readonly ?AllocationStatus $previousStatus,
    ) {
    }
}
