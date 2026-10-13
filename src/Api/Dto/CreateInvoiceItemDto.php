<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Type;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyApi\Api\Attribute\RequiredDtoProperty;
use Wexample\SymfonyApi\Api\Dto\AbstractDto;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * A line added to a draft, under the names of its entity: a unit price
 * (`priceRaw`) in minor units, a quantity scaled by 100, a VAT rate
 * (`priceVat`) in basis points — the ledger's default rate when none is given.
 */
class CreateInvoiceItemDto extends AbstractDto
{
    #[Type(VariableHelper::VARIABLE_TYPE_STRING)]
    #[RequiredDtoProperty]
    public string $invoice;

    #[NotBlank]
    #[RequiredDtoProperty]
    public string $title;

    #[RequiredDtoProperty]
    public int $priceRaw;

    #[PositiveOrZero]
    public int $quantity = InvoiceItem::QUANTITY_SCALE;

    #[PositiveOrZero]
    public ?int $priceVat = null;

    public ?string $description = null;

    public ?string $reference = null;

    public ?string $unit = null;

    public bool $goods = false;

    /** Construction works between VAT-registered companies of one country: the client self-assesses the VAT. */
    public bool $domesticReverseCharge = false;
}
