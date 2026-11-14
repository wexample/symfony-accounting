<?php

declare(strict_types=1);

namespace Wexample\SymfonyAccounting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wexample\SymfonyAccounting\Helper\LetterHelper;

/**
 * Letters count in bijective base 26: no zero digit, Z is followed by AA.
 */
class LetterHelperTest extends TestCase
{
    public function testLettersAndNumbersAreTheSameCount(): void
    {
        foreach ([1 => 'A', 26 => 'Z', 27 => 'AA', 52 => 'AZ', 53 => 'BA', 702 => 'ZZ', 703 => 'AAA'] as $number => $letter) {
            $this->assertSame($letter, LetterHelper::fromNumber($number));
            $this->assertSame($number, LetterHelper::toNumber($letter));
        }

        $this->assertSame('', LetterHelper::fromNumber(0));
        // Lower case reads as upper case.
        $this->assertSame(28, LetterHelper::toNumber('ab'));

        for ($number = 1; $number <= 2000; ++$number) {
            $this->assertSame($number, LetterHelper::toNumber(LetterHelper::fromNumber($number)));
        }
    }

    public function testNextFollowsTheHighestLetterUsed(): void
    {
        $this->assertSame('A', LetterHelper::next([]));
        $this->assertSame('A', LetterHelper::next([null, '']));
        // The highest, not the last nor the longest string order: Z < AA.
        $this->assertSame('AB', LetterHelper::next(['Z', 'AA', null, 'C']));
        $this->assertSame('D', LetterHelper::next(['C', 'A']));
    }
}
