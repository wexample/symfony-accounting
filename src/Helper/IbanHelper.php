<?php

namespace Wexample\SymfonyAccounting\Helper;

class IbanHelper
{
    public static function normalize(string $iban): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $iban));
    }

    /**
     * ISO 13616: letters as numbers (A = 10), country and check digits moved to
     * the end, the whole modulo 97 must be 1.
     */
    public static function isValid(string $iban): bool
    {
        $iban = static::normalize($iban);

        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) {
            return false;
        }

        $digits = '';
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return 1 === $remainder;
    }

    /**
     * "BE68539007547034" → "BE68 5390 0754 7034".
     */
    public static function format(string $iban): string
    {
        return trim(chunk_split(static::normalize($iban), 4, ' '));
    }
}
