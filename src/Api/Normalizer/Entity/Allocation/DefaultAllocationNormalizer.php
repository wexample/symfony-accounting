<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\Allocation;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\AllocationDto;
use Wexample\SymfonyAccounting\Entity\Allocation;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\AllocationEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultAllocationNormalizer extends AbstractEntityNormalizer
{
    use AllocationEntityManipulatorTrait;

    public function normalizeEntity(
        Allocation|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return AllocationDto::fromEntity($entity);
    }
}
