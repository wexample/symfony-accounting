<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\EntryLine;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\EntryLineDto;
use Wexample\SymfonyAccounting\Entity\EntryLine;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\EntryLineEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultEntryLineNormalizer extends AbstractEntityNormalizer
{
    use EntryLineEntityManipulatorTrait;

    public function normalizeEntity(
        EntryLine|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return EntryLineDto::fromEntity($entity);
    }
}
