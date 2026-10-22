<?php

namespace Wexample\SymfonyAccounting\Tests\Fixtures\App\Access;

use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Interface\LedgerGroupResolverInterface;

/**
 * Ties a ledger to its group through its external reference, `firm:acme`.
 */
final class FirmGroupResolver implements LedgerGroupResolverInterface
{
    /** @var array<string, FirmGroup> */
    public static array $groups = [];

    public function getGroup(Ledger $ledger): ?object
    {
        return self::$groups[$ledger->getExternalReference() ?? ''] ?? null;
    }
}
