<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Iban;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Wexample\SymfonyApi\Api\Dto\AbstractDto;

/**
 * A customer or supplier, written. On creation `ledger` and `name` are
 * required; on update only the fields sent change. `country` is the id of a
 * symfony-geo Country.
 */
class PartyInputDto extends AbstractDto
{
    public ?string $ledger = null;

    public ?string $name = null;

    public ?bool $customer = null;

    public ?bool $supplier = null;

    public ?bool $individual = null;

    #[Email]
    public ?string $email = null;

    public ?string $phone = null;

    public ?string $legalIdentifier = null;

    public ?string $vatNumber = null;

    #[Iban]
    public ?string $iban = null;

    #[PositiveOrZero]
    public ?int $paymentTermDays = null;

    public ?string $postalAddress = null;

    public ?string $postCode = null;

    public ?string $city = null;

    public ?string $country = null;

    public ?string $notes = null;

    public ?string $externalReference = null;
}
