<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Symfony\Component\Validator\Constraints\Count;
use Wexample\SymfonyApi\Api\Attribute\RequiredDtoProperty;
use Wexample\SymfonyApi\Api\Dto\AbstractDto;

/**
 * Entry lines of one account that settle each other: their ids.
 */
class LetterLinesDto extends AbstractDto
{
    /** @var list<string> */
    #[Count(min: 2)]
    #[RequiredDtoProperty]
    public array $lines;
}
