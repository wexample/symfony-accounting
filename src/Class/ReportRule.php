<?php

namespace Wexample\SymfonyAccounting\Class;

/**
 * Which accounts feed a report line: those starting with `prefix`, minus the
 * excluded prefixes, and — for accounts that can sit on either side (bank,
 * VAT, current accounts) — only when their balance is on `side`.
 */
final readonly class ReportRule
{
    public const string SIDE_ANY = 'any';
    public const string SIDE_DEBIT = 'debit';
    public const string SIDE_CREDIT = 'credit';

    /**
     * @param list<string> $exclude
     */
    public function __construct(
        public string $prefix,
        public string $side = self::SIDE_ANY,
        public array $exclude = [],
    ) {
    }

    /**
     * "512", "512D" (debit balances only), "445C", "60!603!609" (60 without 603 and 609).
     */
    public static function parse(string $rule): self
    {
        $parts = explode('!', $rule);
        $prefix = array_shift($parts);
        $side = self::SIDE_ANY;

        if (str_ends_with($prefix, 'D')) {
            $side = self::SIDE_DEBIT;
            $prefix = substr($prefix, 0, -1);
        } elseif (str_ends_with($prefix, 'C')) {
            $side = self::SIDE_CREDIT;
            $prefix = substr($prefix, 0, -1);
        }

        return new self($prefix, $side, $parts);
    }

    public function matches(
        string $accountNumber,
        int $balance
    ): bool {
        if (! str_starts_with($accountNumber, $this->prefix)) {
            return false;
        }

        foreach ($this->exclude as $excluded) {
            if (str_starts_with($accountNumber, $excluded)) {
                return false;
            }
        }

        return match ($this->side) {
            self::SIDE_DEBIT => $balance > 0,
            self::SIDE_CREDIT => $balance < 0,
            default => true,
        };
    }
}
