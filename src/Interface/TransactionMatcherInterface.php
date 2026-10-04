<?php

namespace Wexample\SymfonyAccounting\Interface;

use Wexample\SymfonyAccounting\Class\MatchProposal;
use Wexample\SymfonyAccounting\Entity\BankTransaction;

/**
 * Proposes what an unexplained bank line is. Implementing the interface is
 * enough to take part in matching runs.
 */
interface TransactionMatcherInterface
{
    public function getName(): string;

    /**
     * Matchers run by decreasing priority; the first confident enough wins.
     */
    public function getPriority(): int;

    /**
     * @return list<MatchProposal>
     */
    public function propose(BankTransaction $transaction): array;
}
