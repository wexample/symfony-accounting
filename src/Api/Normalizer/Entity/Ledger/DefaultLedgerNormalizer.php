<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\Ledger;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\LedgerDto;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\LedgerEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultLedgerNormalizer extends AbstractEntityNormalizer
{
    use LedgerEntityManipulatorTrait;

    public function normalizeEntity(
        Ledger|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return LedgerDto::fromEntity($entity);
    }
}
