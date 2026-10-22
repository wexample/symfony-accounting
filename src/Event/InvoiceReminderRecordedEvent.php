<?php

namespace Wexample\SymfonyAccounting\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Wexample\SymfonyAccounting\Class\DunningNotice;

/**
 * A reminder was decided; the host sends it (mail, letter).
 */
class InvoiceReminderRecordedEvent extends Event
{
    public function __construct(public readonly DunningNotice $notice)
    {
    }
}
