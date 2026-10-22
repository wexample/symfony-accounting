<?php

namespace Wexample\SymfonyAccounting\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Wexample\SymfonyAccounting\Entity\BankTransaction;

/**
 * Dispatched when two bank lines are linked as one internal transfer, or unlinked.
 * `outgoing` is the negative line, owner of the link.
 */
class TransferLinkedEvent extends Event
{
    public function __construct(
        public readonly BankTransaction $outgoing,
        public readonly BankTransaction $incoming,
        public readonly bool $linked = true,
    ) {
    }
}
