<?php

namespace Wexample\SymfonyAccounting\Enum;

enum VatPeriodicity: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';
    /** Not liable for VAT (franchise, exempt activity). */
    case None = 'none';

    public function getMonths(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Annual, self::None => 12,
        };
    }
}
