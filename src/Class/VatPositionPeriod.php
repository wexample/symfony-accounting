<?php

namespace Wexample\SymfonyAccounting\Class;

use DateTimeImmutable;

/**
 * One column of the VAT position: what the period collected and may deduct,
 * the deposits paid in it, those expected. Minor units.
 */
final class VatPositionPeriod
{
    /**
     * @param int $deductibleAssets The part of $deductible paid on fixed assets.
     */
    public function __construct(
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly int $collected,
        public readonly int $deductible,
        public readonly int $deductibleAssets,
        public readonly int $depositsPaid,
        public readonly int $depositsExpected,
    ) {
    }

    public function getNet(): int
    {
        return $this->collected - $this->deductible;
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
            'collected' => $this->collected,
            'deductible' => $this->deductible,
            'deductibleAssets' => $this->deductibleAssets,
            'net' => $this->getNet(),
            'depositsPaid' => $this->depositsPaid,
            'depositsExpected' => $this->depositsExpected,
        ];
    }
}
