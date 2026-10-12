<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\BankAccount;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\BankAccountDto;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\BankAccountEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultBankAccountNormalizer extends AbstractEntityNormalizer
{
    use BankAccountEntityManipulatorTrait;

    public function normalizeEntity(
        BankAccount|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return BankAccountDto::fromEntity($entity);
    }
}
