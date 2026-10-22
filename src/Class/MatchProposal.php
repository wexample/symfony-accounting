<?php

namespace Wexample\SymfonyAccounting\Class;

use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Party;

/**
 * What a matcher thinks a bank line is: a payment of a document, an amount for
 * an account, or the other half of an internal transfer.
 *
 * Confidence goes from 0 to 100; proposals above the matching threshold become
 * pending allocations, validated at once when `autoValidate` is set.
 */
final readonly class MatchProposal
{
    public function __construct(
        public string $matcher,
        public int $confidence,
        public ?Invoice $invoice = null,
        public ?string $accountNumber = null,
        public ?BankTransaction $transferPeer = null,
        public ?int $amount = null,
        public ?Party $party = null,
        public int $vatRate = 0,
        public ?string $label = null,
        public bool $autoValidate = false,
    ) {
    }
}
