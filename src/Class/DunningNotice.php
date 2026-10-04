<?php

namespace Wexample\SymfonyAccounting\Class;

use Wexample\SymfonyAccounting\Entity\Invoice;

/**
 * A reminder that is due: which document, how late, at which level.
 */
final readonly class DunningNotice
{
    public function __construct(
        public Invoice $invoice,
        public int $level,
        public int $daysLate,
        public int $remaining,
    ) {
    }
}
