<?php

namespace Wexample\SymfonyAccounting\Service\Ledger;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;

/**
 * The ledgers the signed-in user may reach: what a list of ledgers shows.
 */
class LedgerAccessService
{
    public function __construct(
        private readonly LedgerRepository $ledgerRepository,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @return list<Ledger>
     */
    public function getAccessible(LedgerPermission $permission = LedgerPermission::VIEW): array
    {
        return array_values(array_filter(
            $this->ledgerRepository->findAll(),
            fn (Ledger $ledger) => $this->authorizationChecker->isGranted($permission->value, $ledger)
        ));
    }
}
