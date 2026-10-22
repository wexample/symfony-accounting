<?php

namespace Wexample\SymfonyAccounting\Class;

use DateTimeImmutable;

/**
 * A balance a bank file states, at the end of a day.
 */
final readonly class ParsedBalance
{
    public function __construct(
        public DateTimeImmutable $date,
        public int $balance,
    ) {
    }
}
