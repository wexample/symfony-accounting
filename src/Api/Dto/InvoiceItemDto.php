<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A line of a document. The quantity is scaled by 100 (150 = 1.5), the prices
 * are in minor units, the VAT rate in basis points (2000 = 20 %).
 */
class InvoiceItemDto extends AbstractEntityDto
{
    public int $position;

    public string $title;

    public ?string $description;

    public ?string $reference;

    public int $quantity;

    public ?string $unit;

    public int $unitPrice;

    public int $vatRate;

    public int $total;

    /**
     * @param InvoiceItem $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);

        $dto->position = $entity->getPosition();
        $dto->title = $entity->getTitle();
        $dto->description = $entity->getDescription();
        $dto->reference = $entity->getReference();
        $dto->quantity = (int) $entity->getQuantity();
        $dto->unit = $entity->getUnit();
        $dto->unitPrice = (int) $entity->getPriceRaw();
        $dto->vatRate = (int) $entity->getPriceVat();
        $dto->total = $entity->calcPriceNet();

        return $dto;
    }
}
