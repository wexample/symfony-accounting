<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A line of a document, under the names of its entity. The quantity is scaled
 * by 100 (150 = 1.5), the unit price (`priceRaw`) is in minor units, the VAT
 * rate (`priceVat`) in basis points (2000 = 20 %). What the line comes to is
 * computed, and travels in its metadata (`priceNet`).
 */
class InvoiceItemDto extends AbstractEntityDto
{
    public int $position;

    public string $title;

    public ?string $description;

    public ?string $reference;

    public int $quantity;

    public ?string $unit;

    public int $priceRaw;

    public int $priceVat;

    public bool $goods;

    /** Construction works between VAT-registered companies of one country: the client self-assesses the VAT. */
    public bool $domesticReverseCharge;

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
        $dto->priceRaw = (int) $entity->getPriceRaw();
        $dto->priceVat = (int) $entity->getPriceVat();
        $dto->goods = $entity->isGoods();
        $dto->domesticReverseCharge = $entity->isDomesticReverseCharge();

        return $dto;
    }
}
