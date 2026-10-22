<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Symfony\Component\Validator\Constraints\Currency;
use Symfony\Component\Validator\Constraints\Date;
use Symfony\Component\Validator\Constraints\Regex;
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

    /** The document's currency, ISO 4217; the ledger's when absent. */
    #[Currency]
    public ?string $currencyCode = null;

    /** One unit of it in the ledger's currency; taken from the rate provider at emission when absent. */
    #[Regex('/^\d+(\.\d+)?$/')]
    public ?string $exchangeRate = null;
}
