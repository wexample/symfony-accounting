<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\Journal;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A journal of the ledger: sales, purchases, bank, miscellaneous…
 */
class JournalDto extends AbstractEntityDto
{
    public string $code;

    public string $label;

    public string $type;

    /**
     * @param Journal $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);

        $dto->code = $entity->getCode();
        $dto->label = $entity->getLabel();
        $dto->type = $entity->getType()->value;

        return $dto;
    }
}
