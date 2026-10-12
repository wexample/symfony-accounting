<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\Account;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * An account of the ledger's chart.
 */
class AccountDto extends AbstractEntityDto
{
    public string $number;

    public string $label;

    public string $nature;

    public bool $lettrable;

    public bool $active;

    /**
     * @param Account $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);

        $dto->number = $entity->getNumber();
        $dto->label = $entity->getLabel();
        $dto->nature = $entity->getNature()->value;
        $dto->lettrable = $entity->isLettrable();
        $dto->active = $entity->isActive();

        return $dto;
    }
}
