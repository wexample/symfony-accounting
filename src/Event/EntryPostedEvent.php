<?php

namespace Wexample\SymfonyAccounting\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Wexample\SymfonyAccounting\Entity\JournalEntry;

class EntryPostedEvent extends Event
{
    public function __construct(public readonly JournalEntry $entry)
    {
    }
}
