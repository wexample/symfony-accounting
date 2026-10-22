<?php

namespace Wexample\SymfonyAccounting\Class;

/**
 * What a late payment costs: interest lines (one per month for the monthly mode,
 * one for the annual mode) and the flat recovery fee.
 */
final readonly class LatePenalty
{
    /**
     * @param list<array{label: string, days: int, amount: int}> $lines
     */
    public function __construct(
        public int $daysLate,
        public int $base,
        public array $lines,
        public int $flatFee,
    ) {
    }

    public function getInterest(): int
    {
        return array_sum(array_column($this->lines, 'amount'));
    }

    public function getTotal(): int
    {
        return $this->getInterest() + $this->flatFee;
    }
}
