<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Wexample\SymfonyApi\Api\Dto\AbstractDto;

/**
 * What the books say of their keeper, and how they invoice; only the fields
 * sent change. `country` is a symfony-geo Country id, `defaultBankAccount` one
 * of the ledger's accounts, `settings` merged key by key (null removes a key).
 */
class LedgerInputDto extends AbstractDto
{
    public ?string $name = null;

    public ?string $legalForm = null;

    public ?string $legalIdentifier = null;

    public ?string $vatNumber = null;

    #[Email]
    public ?string $email = null;

    public ?string $phone = null;

    public ?string $website = null;

    public ?string $legalMentions = null;

    public ?string $postalAddress = null;

    public ?string $postCode = null;

    public ?string $city = null;

    public ?string $country = null;

    public ?string $invoicePrefix = null;

    #[PositiveOrZero]
    public ?int $paymentTermDays = null;

    public ?string $defaultBankAccount = null;

    public ?array $settings = null;
}
