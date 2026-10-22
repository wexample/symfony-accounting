<?php

namespace Wexample\SymfonyAccounting\Enum;

/**
 * What one may do with a ledger and everything it holds: what the
 * application asks, `is_granted('accounting.view', $invoice)`.
 */
enum LedgerPermission: string
{
    /** Read its documents, entries and reports. */
    case VIEW = 'accounting.view';

    /** Write in it: invoices, entries, bank allocations. */
    case EDIT = 'accounting.edit';

    /** Its settings and its closing. */
    case ADMINISTER = 'accounting.administer';

    /**
     * The key of the configuration, `access.roles.view`.
     */
    public function getKey(): string
    {
        return strtolower($this->name);
    }
}
