<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\Party;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\PartyDto;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\PartyEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultPartyNormalizer extends AbstractEntityNormalizer
{
    use PartyEntityManipulatorTrait;

    public function normalizeEntity(
        Party|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return PartyDto::fromEntity($entity);
    }
}
