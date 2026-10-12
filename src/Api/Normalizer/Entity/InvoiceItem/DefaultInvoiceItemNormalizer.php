<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\InvoiceItem;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\InvoiceItemDto;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\InvoiceItemEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultInvoiceItemNormalizer extends AbstractEntityNormalizer
{
    use InvoiceItemEntityManipulatorTrait;

    public function normalizeEntity(
        InvoiceItem|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return InvoiceItemDto::fromEntity($entity);
    }
}
