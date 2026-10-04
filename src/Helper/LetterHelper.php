<?php

namespace Wexample\SymfonyAccounting\Helper;

/**
 * Lettering codes: A, B, … Z, AA, AB, … (bijective base 26).
 */
class LetterHelper
{
    public static function toNumber(string $letter): int
    {
        $number = 0;

        foreach (str_split(strtoupper($letter)) as $char) {
            $number = $number * 26 + (ord($char) - 64);
        }

        return $number;
    }

    public static function fromNumber(int $number): string
    {
        $letter = '';

        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $letter = chr(65 + $remainder).$letter;
            $number = intdiv($number - 1, 26);
        }

        return $letter;
    }

    /**
     * @param iterable<?string> $used
     */
    public static function next(iterable $used): string
    {
        $max = 0;

        foreach ($used as $letter) {
            if (null !== $letter && '' !== $letter) {
                $max = max($max, static::toNumber($letter));
            }
        }

        return static::fromNumber($max + 1);
    }
}
