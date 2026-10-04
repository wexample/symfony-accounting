<?php

namespace Wexample\SymfonyAccounting\Helper;

class EuHelper
{
    /**
     * EU member states, ISO alpha-2. Greece uses "EL" in VAT numbers but "GR" here.
     */
    final public const array MEMBER_STATES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    public static function isMemberState(?string $countryCode): bool
    {
        return null !== $countryCode && in_array(strtoupper($countryCode), self::MEMBER_STATES, true);
    }

    /**
     * The country prefix of an EU VAT number ("EL" → "GR").
     */
    public static function getVatNumberCountry(string $vatNumber): ?string
    {
        $prefix = strtoupper(substr(trim($vatNumber), 0, 2));

        if ('EL' === $prefix) {
            return 'GR';
        }

        return ctype_alpha($prefix) ? $prefix : null;
    }
}
