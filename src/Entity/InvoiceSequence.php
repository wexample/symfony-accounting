<?php

namespace Wexample\SymfonyAccounting\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Entity\Traits\BelongsToLedgerTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * The last number given in one series of one year: gap-free numbering.
 */
#[ORM\Entity]
#[ORM\Table(name: 'accounting_invoice_sequence')]
#[ORM\UniqueConstraint(columns: ['ledger_id', 'series', 'year'])]
class InvoiceSequence extends AbstractEntity
{
    use BelongsToLedgerTrait;

    #[ORM\Column(type: Types::STRING, length: 20)]
    protected string $series;

    #[ORM\Column(type: Types::INTEGER)]
    protected int $year;

    #[ORM\Column(type: Types::INTEGER)]
    protected int $lastNumber = 0;

    public function getSeries(): string
    {
        return $this->series;
    }

    public function setSeries(string $series): static
    {
        $this->series = $series;

        return $this;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function setYear(int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getLastNumber(): int
    {
        return $this->lastNumber;
    }

    public function increment(): int
    {
        return ++$this->lastNumber;
    }
}
