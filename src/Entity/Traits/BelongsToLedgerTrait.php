<?php

namespace Wexample\SymfonyAccounting\Entity\Traits;

use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Entity\Ledger;

/**
 * Everything bookkeeping-related belongs to one set of books.
 */
trait BelongsToLedgerTrait
{
    #[ORM\ManyToOne(targetEntity: Ledger::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Ledger $ledger;

    public function getLedger(): Ledger
    {
        return $this->ledger;
    }

    public function setLedger(Ledger $ledger): static
    {
        $this->ledger = $ledger;

        return $this;
    }
}
