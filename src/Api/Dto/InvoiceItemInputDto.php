<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Wexample\SymfonyApi\Api\Dto\AbstractDto;

/**
 * What a line of a draft may change; only the fields sent do. A VAT rate sent
 * as null goes back to the ledger's default rate.
 */
class InvoiceItemInputDto extends AbstractDto
{
    #[NotBlank(allowNull: true)]
    public ?string $title = null;

    public ?string $description = null;

    public ?string $reference = null;

    public ?int $priceRaw = null;

    #[PositiveOrZero]
    public ?int $quantity = null;

    public ?string $unit = null;

    #[PositiveOrZero]
    public ?int $priceVat = null;

    public ?bool $goods = null;

    public ?bool $domesticReverseCharge = null;
}
