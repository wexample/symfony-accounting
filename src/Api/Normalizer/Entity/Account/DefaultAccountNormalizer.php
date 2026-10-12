<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\Account;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\AccountDto;
use Wexample\SymfonyAccounting\Entity\Account;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\AccountEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultAccountNormalizer extends AbstractEntityNormalizer
{
    use AccountEntityManipulatorTrait;

    public function normalizeEntity(
        Account|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return AccountDto::fromEntity($entity);
    }
}
