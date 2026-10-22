<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\InvoiceTransmission;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\InvoiceTransmissionDto;
use Wexample\SymfonyAccounting\Entity\InvoiceTransmission;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\InvoiceTransmissionEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultInvoiceTransmissionNormalizer extends AbstractEntityNormalizer
{
    use InvoiceTransmissionEntityManipulatorTrait;

    public function normalizeEntity(
        InvoiceTransmission|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return InvoiceTransmissionDto::fromEntity($entity);
    }
}
