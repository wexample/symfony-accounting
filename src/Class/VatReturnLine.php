<?php

namespace Wexample\SymfonyAccounting\Class;

use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\VatKind;

/**
 * Totals of one VAT code over a period.
 */
final class VatReturnLine
{
    public int $base = 0;

    public int $due = 0;

    public int $deductible = 0;

    /**
     * The reversed parts already netted in base, due and deductible: credit notes,
     * which some forms report in boxes of their own. Positive amounts.
     */
    public int $baseReversed = 0;

    public int $dueReversed = 0;

    public int $deductibleReversed = 0;

    /** @var array<string, int> Base per account number, for forms splitting by kind of expense. */
    public array $accountBases = [];

    public function __construct(
        public readonly string $code,
        public readonly ?InvoiceDirection $direction,
        public readonly ?VatKind $kind,
        public readonly int $rate,
    ) {
    }

    /**
     * The base booked on accounts starting with one of the prefixes.
     *
     * @param list<string> $prefixes
     */
    public function getBaseOnAccounts(array $prefixes): int
    {
        $sum = 0;

        foreach ($this->accountBases as $number => $base) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) $number, $prefix)) {
                    $sum += $base;

                    break;
                }
            }
        }

        return $sum;
    }
}
