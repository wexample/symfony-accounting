<?php

namespace Wexample\SymfonyAccounting\Class;

/**
 * What a bank file contains: lines, stated balances, and the account it is about
 * when the format says so.
 */
final class ParsedStatement
{
    /** @var list<ParsedTransaction> */
    public array $transactions = [];

    /** @var list<ParsedBalance> */
    public array $balances = [];

    public function __construct(
        public ?string $iban = null,
        public ?string $currencyCode = null,
    ) {
    }

    public function addTransaction(ParsedTransaction $transaction): self
    {
        $this->transactions[] = $transaction;

        return $this;
    }

    public function addBalance(ParsedBalance $balance): self
    {
        $this->balances[] = $balance;

        return $this;
    }

    public function getTotal(): int
    {
        return array_sum(array_map(fn (ParsedTransaction $t) => $t->amount, $this->transactions));
    }
}
