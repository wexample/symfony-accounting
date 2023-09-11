<?php

namespace Wexample\SymfonyAccounting\Service\Entity;

use Wexample\SymfonyAccounting\Entity\AbstractAccountingTransactionEntity;
use Wexample\SymfonyHelpers\Service\Entity\AbstractEntityService;

class AbstractAccountingTransactionEntityService extends AbstractEntityService
{
    public static function getEntityClassName(): string
    {
        return AbstractAccountingTransactionEntity::class;
    }

    public function saveTransactionIfNotExists(
        AbstractAccountingTransactionEntity $transaction
    ): bool {
        if (!$this->findSameTransaction($transaction)) {
            $this->save($transaction);

            return true;
        }

        return false;
    }
}