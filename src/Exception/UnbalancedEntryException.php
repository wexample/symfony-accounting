<?php

namespace Wexample\SymfonyAccounting\Exception;

use Wexample\SymfonyAccounting\Entity\JournalEntry;

class UnbalancedEntryException extends AccountingException
{
    public function __construct(public readonly JournalEntry $entry)
    {
        parent::__construct(sprintf(
            'Entry "%s" is not balanced: %d debit, %d credit.',
            $entry->getLabel(),
            $entry->getTotalDebit(),
            $entry->getTotalCredit()
        ));
    }
}
