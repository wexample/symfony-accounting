<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\Allocation;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * What a bank line pays: a document, or an account of the chart.
 */
class AllocationDto extends AbstractEntityDto
{
    public BankTransaction $transaction;

    public ?Invoice $invoice;

    public ?string $accountNumber;

    public int $amount;

    public string $status;

    public string $origin;

    public ?string $label;

    /**
     * @param Allocation $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);

        $dto->transaction = $entity->getTransaction();
        $dto->invoice = $entity->getInvoice();
        $dto->accountNumber = $entity->getAccountNumber();
        $dto->amount = $entity->getAmount();
        $dto->status = $entity->getStatus()->value;
        $dto->origin = $entity->getOrigin();
        $dto->label = $entity->getLabel();

        return $dto;
    }
}
