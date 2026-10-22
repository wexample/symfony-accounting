<?php

namespace Wexample\SymfonyAccounting\Interface;

use Wexample\SymfonyAccounting\Entity\Ledger;

/**
 * The application's group a ledger belongs to — a firm, a client's team —,
 * found through `Ledger::getExternalReference()`. Access is then the grants
 * held on that group (symfony-user's memberships answer them). None
 * implemented: access by role only.
 */
interface LedgerGroupResolverInterface
{
    public function getGroup(Ledger $ledger): ?object;
}
