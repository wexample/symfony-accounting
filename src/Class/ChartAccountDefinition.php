<?php

namespace Wexample\SymfonyAccounting\Class;

use Wexample\SymfonyAccounting\Enum\AccountNature;

/**
 * One account of a chart dataset, before it is loaded into a ledger.
 */
final readonly class ChartAccountDefinition
{
    public function __construct(
        public string $number,
        public string $label,
        public AccountNature $nature = AccountNature::Both,
        public bool $lettrable = false,
        public ?string $counterpartNumber = null,
    ) {
    }
}
