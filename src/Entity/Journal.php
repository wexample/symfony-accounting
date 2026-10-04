<?php

namespace Wexample\SymfonyAccounting\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Entity\Traits\BelongsToLedgerTrait;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Repository\JournalRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

#[ORM\Entity(repositoryClass: JournalRepository::class)]
#[ORM\Table(name: 'accounting_journal')]
#[ORM\UniqueConstraint(columns: ['ledger_id', 'code'])]
class Journal extends AbstractEntity
{
    use BelongsToLedgerTrait;

    /** "VE", "AC", "BQ1", "OD", "AN"… */
    #[ORM\Column(type: Types::STRING, length: 10)]
    protected string $code;

    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $label;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: JournalType::class)]
    protected JournalType $type;

    public function __toString(): string
    {
        return $this->code;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getType(): JournalType
    {
        return $this->type;
    }

    public function setType(JournalType $type): static
    {
        $this->type = $type;

        return $this;
    }
}
