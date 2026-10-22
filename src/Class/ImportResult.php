<?php

namespace Wexample\SymfonyAccounting\Class;

use Wexample\SymfonyAccounting\Entity\BankTransaction;

final class ImportResult
{
    /** @var list<BankTransaction> */
    public array $created = [];

    public int $duplicates = 0;

    public int $balances = 0;

    public function __construct(public readonly string $batch)
    {
    }

    public function count(): int
    {
        return count($this->created);
    }
}
