<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\JournalEntry;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\JournalEntryDto;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\JournalEntryEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultJournalEntryNormalizer extends AbstractEntityNormalizer
{
    use JournalEntryEntityManipulatorTrait;

    public function normalizeEntity(
        JournalEntry|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return JournalEntryDto::fromEntity($entity);
    }
}
