<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Symfony\Component\Validator\Constraints\Currency;
use Symfony\Component\Validator\Constraints\Date;
use Symfony\Component\Validator\Constraints\Regex;
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

    /** The document's currency, ISO 4217; the ledger's when absent. */
    #[Currency]
    public ?string $currencyCode = null;

    /** One unit of it in the ledger's currency; taken from the rate provider at emission when absent. */
    #[Regex('/^\d+(\.\d+)?$/')]
    public ?string $exchangeRate = null;

    #[Date]
    public ?string $dateValidUntil = null;

    #[PositiveOrZero]
    public ?int $paymentTermDays = null;
}
