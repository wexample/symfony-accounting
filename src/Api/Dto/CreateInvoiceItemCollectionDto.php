<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyApi\Api\Dto\AbstractEntityCollectionDto;

/**
 * Lines added to a draft at once — the rows of a pasted or uploaded table, read
 * into lines by whoever sent them. Every line is checked before any is added.
 */
class CreateInvoiceItemCollectionDto extends AbstractEntityCollectionDto
{
    public static function getEntityDtoClass(): string
    {
        return CreateInvoiceItemDto::class;
    }
}
