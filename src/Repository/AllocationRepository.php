<?php

namespace Wexample\SymfonyAccounting\Repository;

use Wexample\SymfonyAccounting\Entity\Allocation;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

/**
 * @method Allocation|null find($id, $lockMode = null, $lockVersion = null)
 * @method Allocation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AllocationRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return Allocation::class;
    }

    /**
     * Whether someone refused this pairing before: matchers must not propose it again.
     */
    public function isExcluded(
        BankTransaction $transaction,
        ?Invoice $invoice = null,
        ?string $accountNumber = null
    ): bool {
        $criteria = ['transaction' => $transaction, 'status' => AllocationStatus::Excluded];

        if ($invoice) {
            $criteria['invoice'] = $invoice;
        } else {
            $criteria['accountNumber'] = $accountNumber;
        }

        return null !== $this->findOneBy($criteria);
    }
}
