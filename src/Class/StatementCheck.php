<?php

namespace Wexample\SymfonyAccounting\Class;

use Wexample\SymfonyAccounting\Entity\BankStatement;

/**
 * A stated balance against what the imported lines add up to.
 */
final readonly class StatementCheck
{
    public function __construct(
        public BankStatement $statement,
        public int $computed,
    ) {
    }

    public function getDifference(): int
    {
        return $this->statement->getBalance() - $this->computed;
    }

    public function isReconciled(): bool
    {
        return 0 === $this->getDifference();
    }
}
