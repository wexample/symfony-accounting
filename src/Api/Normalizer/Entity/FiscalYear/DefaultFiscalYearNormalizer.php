<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\FiscalYear;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\FiscalYearDto;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\FiscalYearEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultFiscalYearNormalizer extends AbstractEntityNormalizer
{
    use FiscalYearEntityManipulatorTrait;

    public function normalizeEntity(
        FiscalYear|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return FiscalYearDto::fromEntity($entity);
    }
}
