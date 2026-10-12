<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\Invoice;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\InvoiceDto;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\InvoiceEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultInvoiceNormalizer extends AbstractEntityNormalizer
{
    use InvoiceEntityManipulatorTrait;

    public function normalizeEntity(
        Invoice|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return InvoiceDto::fromEntity($entity);
    }
}
