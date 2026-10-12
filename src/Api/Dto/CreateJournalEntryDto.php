<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Date;
use Symfony\Component\Validator\Constraints\NotBlank;
use Wexample\SymfonyApi\Api\Attribute\RequiredDtoProperty;
use Wexample\SymfonyApi\Api\Dto\AbstractDto;

/**
 * An entry written by hand: its journal (code), date, label, and lines
 * `{account, debit|credit, label?, party?}`, amounts in minor units.
 */
class CreateJournalEntryDto extends AbstractDto
{
    #[RequiredDtoProperty]
    public string $ledger;

    #[NotBlank]
    #[RequiredDtoProperty]
    public string $journal;

    #[Date]
    #[RequiredDtoProperty]
    public string $date;

    #[NotBlank]
    #[RequiredDtoProperty]
    public string $label;

    public ?string $pieceReference = null;

    /** @var list<array{account: string, debit?: int, credit?: int, label?: string, party?: string}> */
    #[Count(min: 2)]
    #[RequiredDtoProperty]
    public array $lines;
}
