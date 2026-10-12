<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Symfony\Component\Validator\Constraints\Type;
use Wexample\SymfonyApi\Api\Attribute\RequiredDtoProperty;
use Wexample\SymfonyApi\Api\Dto\AbstractDto;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * Lines added to a draft from a table: its rows, header left out, and the column
 * of each field (`title`, `description`, `unitPrice`, `quantity`, `vatRate`).
 */
class ImportInvoiceItemsDto extends AbstractDto
{
    #[Type(VariableHelper::VARIABLE_TYPE_STRING)]
    #[RequiredDtoProperty]
    public string $invoice;

    /** @var list<list<?string>> */
    #[RequiredDtoProperty]
    public array $rows;

    /** @var array<string, int> */
    #[RequiredDtoProperty]
    public array $columns;
}
