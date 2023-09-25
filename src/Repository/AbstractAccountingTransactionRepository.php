<?php

namespace Wexample\SymfonyAccounting\Repository;


use DateTimeInterface;
use Wexample\SymfonyAccounting\Entity\AbstractAccountingTransactionEntity;
use Wexample\SymfonyAccounting\Entity\AbstractBankOrganizationEntity;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

abstract class AbstractAccountingTransactionRepository extends AbstractRepository
{
    public function createAccountingTransaction(
        AbstractBankOrganizationEntity $bank,
        string $type,
        DateTimeInterface $dateCreated,
        string $description,
        int $amount
    ): AbstractAccountingTransactionEntity {
        $className = $this->getEntityType();

        $transaction = new $className();

        $transaction->setBank($bank);

        $transaction->setType(
            $type
        );

        $transaction->setDateCreated(
            $dateCreated
        );

        $transaction->setDescription(
            $description
        );

        $transaction->setAmount(
            $amount
        );

        return $transaction;
    }

    /**
     * @return string Might be replaced by EntityManipulator interface.
     */
    abstract function getEntityType(): string;
}
