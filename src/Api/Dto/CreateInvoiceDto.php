<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Symfony\Component\Validator\Constraints\Date;
use Symfony\Component\Validator\Constraints\Type;
use Wexample\SymfonyApi\Api\Attribute\RequiredDtoProperty;
use Wexample\SymfonyApi\Api\Dto\AbstractDto;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * A draft document: in which books, which kind, for whom. Its lines are added
 * one by one (`api/invoice-item/create`), then it is emitted.
 */
class CreateInvoiceDto extends AbstractDto
{
    #[Type(VariableHelper::VARIABLE_TYPE_STRING)]
    #[RequiredDtoProperty]
    public string $ledger;

    /** An InvoiceType value. */
    public string $type = 'bill';

    /** An InvoiceDirection value. */
    public string $direction = 'sale';

    public ?string $party = null;

    public ?string $title = null;

    public ?string $note = null;

    #[Date]
    public ?string $dateInvoice = null;
}
