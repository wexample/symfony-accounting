<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Symfony\Component\Validator\Constraints\Positive;
use Wexample\SymfonyApi\Api\Dto\AbstractDto;

/**
 * Cancelling a bill: all of it, or `amount` before VAT of it.
 */
class CreateCreditNoteDto extends AbstractDto
{
    #[Positive]
    public ?int $amount = null;

    public ?string $reason = null;
}
