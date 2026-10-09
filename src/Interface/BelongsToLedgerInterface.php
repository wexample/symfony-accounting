<?php

namespace Wexample\SymfonyAccounting\Interface;

use Wexample\SymfonyAccounting\Entity\Ledger;

/**
 * Held by a ledger: who may see or change it is who may see or change the
 * ledger.
 */
interface BelongsToLedgerInterface
{
    public function getLedger(): Ledger;
}
