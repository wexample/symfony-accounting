<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A line of a bank statement, under the names of its entity. How much of it
 * no document or account explains yet travels in its metadata.
 */
class BankTransactionDto extends AbstractEntityDto
{
    public BankAccount $bankAccount;

    public string $date;

    public ?string $valueDate;

    public string $label;

    /** Signed: money in is positive. */
    public int $amount;

    public ?string $counterpartyName;

    public ?string $counterpartyIban;

    public ?string $reference;

    public ?string $paymentReference;

    /**
     * @param BankTransaction $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);

        $dto->bankAccount = $entity->getBankAccount();
        $dto->date = $entity->getDate()->format('Y-m-d');
        $dto->valueDate = $entity->getValueDate()?->format('Y-m-d');
        $dto->label = $entity->getLabel();
        $dto->amount = $entity->getAmount();
        $dto->counterpartyName = $entity->getCounterpartyName();
        $dto->counterpartyIban = $entity->getCounterpartyIban();
        $dto->reference = $entity->getReference();
        $dto->paymentReference = $entity->getPaymentReference();

        return $dto;
    }
}
