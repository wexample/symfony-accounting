<?php

namespace Wexample\SymfonyAccounting\Service\Bank\Matcher;

use Wexample\SymfonyAccounting\Class\MatchProposal;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Interface\TransactionMatcherInterface;
use Wexample\SymfonyAccounting\Repository\ImportPatternRepository;

/**
 * The ledger's label rules (ImportPattern): recurring lines booked on a chosen
 * account, with a party and VAT.
 */
class PatternMatcher implements TransactionMatcherInterface
{
    public function __construct(
        private readonly ImportPatternRepository $patternRepository,
    ) {
    }

    public function getName(): string
    {
        return 'pattern';
    }

    public function getPriority(): int
    {
        return 70;
    }

    public function propose(BankTransaction $transaction): array
    {
        $patterns = $this->patternRepository->findBy(['ledger' => $transaction->getLedger()], ['priority' => 'DESC']);

        foreach ($patterns as $pattern) {
            if ($pattern->getAccountNumber() && $pattern->matches($transaction->getLabel(), $transaction->getAmount())) {
                return [new MatchProposal(
                    $this->getName(),
                    80,
                    accountNumber: $pattern->getAccountNumber(),
                    party: $pattern->getParty(),
                    vatRate: $pattern->getVatRate(),
                    label: $pattern->getLabel(),
                    autoValidate: $pattern->isAutoValidate(),
                )];
            }
        }

        return [];
    }
}
