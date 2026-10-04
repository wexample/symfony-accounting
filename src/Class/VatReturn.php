<?php

namespace Wexample\SymfonyAccounting\Class;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\VatKind;

/**
 * The VAT of a period, code by code, before it is put in a national form.
 *
 * net = due − deductible − credit carried from before − deposits paid.
 * Positive: to pay. Negative: a credit, carried forward or refunded.
 */
final class VatReturn
{
    /** @var array<string, VatReturnLine> */
    public array $lines = [];

    /** VAT credit carried from previous returns. */
    public int $previousCredit = 0;

    /** Deposits paid on account of this return. */
    public int $deposits = 0;

    public function __construct(
        public readonly Ledger $ledger,
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
    ) {
    }

    public function getLine(
        string $code,
        ?InvoiceDirection $direction = null,
        ?VatKind $kind = null,
        int $rate = 0
    ): VatReturnLine {
        return $this->lines[$code] ??= new VatReturnLine($code, $direction, $kind, $rate);
    }

    public function getDue(): int
    {
        return array_sum(array_map(fn (VatReturnLine $line) => $line->due, $this->lines));
    }

    public function getDeductible(): int
    {
        return array_sum(array_map(fn (VatReturnLine $line) => $line->deductible, $this->lines));
    }

    public function getNet(): int
    {
        return $this->getDue() - $this->getDeductible() - $this->previousCredit - $this->deposits;
    }

    /**
     * Sums lines selected by direction, kind and rate (null: any).
     *
     * @param list<VatKind>|VatKind|null $kinds
     * @param list<string>|null $accountPrefixes Restricts the base to these accounts.
     * @return array{base: int, due: int, deductible: int, baseReversed: int, dueReversed: int, deductibleReversed: int}
     */
    public function sum(
        ?InvoiceDirection $direction = null,
        array|VatKind|null $kinds = null,
        ?int $rate = null,
        ?array $accountPrefixes = null
    ): array {
        $kinds = $kinds instanceof VatKind ? [$kinds] : $kinds;
        $sum = ['base' => 0, 'due' => 0, 'deductible' => 0, 'baseReversed' => 0, 'dueReversed' => 0, 'deductibleReversed' => 0];

        foreach ($this->lines as $line) {
            if ((null === $direction || $line->direction === $direction)
                && (null === $kinds || in_array($line->kind, $kinds, true))
                && (null === $rate || $line->rate === $rate)) {
                $sum['base'] += null === $accountPrefixes ? $line->base : $line->getBaseOnAccounts($accountPrefixes);
                $sum['due'] += $line->due;
                $sum['deductible'] += $line->deductible;
                $sum['baseReversed'] += $line->baseReversed;
                $sum['dueReversed'] += $line->dueReversed;
                $sum['deductibleReversed'] += $line->deductibleReversed;
            }
        }

        return $sum;
    }
}
