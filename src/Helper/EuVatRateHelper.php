<?php

namespace Wexample\SymfonyAccounting\Helper;

/**
 * The standard VAT rate of each EU member state, in basis points, for sales taxed
 * in the customer's country (OSS). Reduced rates differ by product and are not
 * listed: an OSS sale is charged the standard rate. Rates as of 2026; a country
 * changing its rate changes this table.
 */
class EuVatRateHelper
{
    final public const array STANDARD_RATES = [
        'AT' => 2000, 'BE' => 2100, 'BG' => 2000, 'CY' => 1900, 'CZ' => 2100, 'DE' => 1900,
        'DK' => 2500, 'EE' => 2400, 'ES' => 2100, 'FI' => 2550, 'FR' => 2000, 'GR' => 2400,
        'HR' => 2500, 'HU' => 2700, 'IE' => 2300, 'IT' => 2200, 'LT' => 2100, 'LU' => 1700,
        'LV' => 2100, 'MT' => 1800, 'NL' => 2100, 'PL' => 2300, 'PT' => 2300, 'RO' => 2100,
        'SE' => 2500, 'SI' => 2200, 'SK' => 2300,
    ];

    public static function getStandardRate(string $countryCode): ?int
    {
        return self::STANDARD_RATES[strtoupper($countryCode)] ?? null;
    }
}
