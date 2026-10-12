<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\BankTransaction;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\BankTransactionDto;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\BankTransactionEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultBankTransactionNormalizer extends AbstractEntityNormalizer
{
    use BankTransactionEntityManipulatorTrait;

    public function normalizeEntity(
        BankTransaction|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return BankTransactionDto::fromEntity($entity);
    }
}
