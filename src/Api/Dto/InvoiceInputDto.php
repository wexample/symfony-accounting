<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Symfony\Component\Validator\Constraints\Date;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Wexample\SymfonyApi\Api\Dto\AbstractDto;

/**
 * What a draft may change; only the fields sent do.
 */
class InvoiceInputDto extends AbstractDto
{
    public ?string $party = null;

    public ?string $title = null;

    public ?string $note = null;

    #[Date]
    public ?string $dateInvoice = null;

    #[Date]
    public ?string $dateValidUntil = null;

    #[PositiveOrZero]
    public ?int $paymentTermDays = null;
}
