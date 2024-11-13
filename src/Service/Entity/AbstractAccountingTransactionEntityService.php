<?php

namespace Wexample\SymfonyAccounting\Service\Entity;

use Wexample\SymfonyAccounting\Entity\AbstractAccountingTransactionEntity;
use Wexample\SymfonyAccounting\Service\Entity\Interface\AccountingTransactionEntityServiceInterface;
use Wexample\SymfonyHelpers\Service\Entity\AbstractEntityService;

abstract class AbstractAccountingTransactionEntityService
    extends AbstractEntityService
    implements AccountingTransactionEntityServiceInterface
{
    public static function getEntityClassName(): string
    {
        return AbstractAccountingTransactionEntity::class;
    }

    public function saveTransactionIfNotExists(
        AbstractAccountingTransactionEntity $transaction
    ): bool {
        if (!$this->findSameTransaction($transaction)) {
            $this->getEntityRepository()->add($transaction);

            return true;
        }

        return false;
    }
}