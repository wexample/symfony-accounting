<?php

namespace Wexample\SymfonyAccounting\Class;

/**
 * How late payments are charged.
 *
 * - annual: amount × annual rate × days late / 365 (the legal formula, FR L441-10,
 *   BE law of 2 August 2002);
 * - monthly: amount × monthly rate × days late / days in that month, per month
 *   (network's historical rule).
 *
 * Rates are basis points; the flat fee (40 € in FR and BE) is in minor units.
 */
final readonly class LatePenaltyPolicy
{
    public const string MODE_ANNUAL = 'annual';
    public const string MODE_MONTHLY = 'monthly';

    public function __construct(
        public string $mode = self::MODE_ANNUAL,
        public int $rate = 1200,
        public int $flatFee = 4000,
        public int $graceDays = 0,
    ) {
    }
}
