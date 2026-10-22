<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

class BankAccountDto extends AbstractEntityDto
{
    public string $label;

    public ?string $iban;

    public ?string $bic;

    public ?string $holder;

    public ?string $bankName;

    /** Its account in the chart (512…). */
    public string $accountNumber;

    public string $currencyCode;

    public ?string $provider;

    public bool $active;

    public ?string $dateLastImport;

    /**
     * @param BankAccount $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);

        $dto->label = $entity->getLabel();
        $dto->iban = $entity->getIban();
        $dto->bic = $entity->getBic();
        $dto->holder = $entity->getHolder();
        $dto->bankName = $entity->getBankName();
        $dto->accountNumber = $entity->getAccountNumber();
        $dto->currencyCode = $entity->getCurrencyCode();
        $dto->provider = $entity->getProvider();
        $dto->active = $entity->isActive();
        $dto->dateLastImport = $entity->getDateLastImport()?->format(DATE_ATOM);

        return $dto;
    }
}
