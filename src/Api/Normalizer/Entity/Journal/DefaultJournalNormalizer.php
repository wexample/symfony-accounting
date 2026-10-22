<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\Journal;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\JournalDto;
use Wexample\SymfonyAccounting\Entity\Journal;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\JournalEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultJournalNormalizer extends AbstractEntityNormalizer
{
    use JournalEntityManipulatorTrait;

    public function normalizeEntity(
        Journal|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return JournalDto::fromEntity($entity);
    }
}
