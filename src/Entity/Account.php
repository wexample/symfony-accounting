<?php

namespace Wexample\SymfonyAccounting\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Entity\Traits\BelongsToLedgerTrait;
use Wexample\SymfonyAccounting\Enum\AccountNature;
use Wexample\SymfonyAccounting\Repository\AccountRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * An account of the ledger's chart. The number is a string of digits ("411",
 * "6061", "512100"), compared by prefix: its first digit is its class.
 */
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounting_account')]
#[ORM\UniqueConstraint(columns: ['ledger_id', 'number'])]
class Account extends AbstractEntity
{
    use BelongsToLedgerTrait;

    #[ORM\Column(type: Types::STRING, length: 20)]
    protected string $number;

    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $label;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: AccountNature::class)]
    protected AccountNature $nature = AccountNature::Both;

    /** Where the account comes from: a chart dataset ("fr_pcg", "be_pcmn") or "custom". */
    #[ORM\Column(type: Types::STRING, length: 30)]
    protected string $source = 'custom';

    /** Lines of this account are matched together (customers, suppliers). */
    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $lettrable = false;

    /** The account usually facing it in an entry (706 → 411). */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    protected ?string $counterpartNumber = null;

    /** VAT code proposed for items booked on this account. */
    #[ORM\Column(type: Types::STRING, length: 30, nullable: true)]
    protected ?string $defaultVatCode = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $active = true;

    public function __toString(): string
    {
        return $this->number.' '.$this->label;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function setNumber(string $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getClass(): int
    {
        return (int) $this->number[0];
    }

    public function startsWith(string $prefix): bool
    {
        return str_starts_with($this->number, $prefix);
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

    public function getNature(): AccountNature
    {
        return $this->nature;
    }

    public function setNature(AccountNature $nature): static
    {
        $this->nature = $nature;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function isLettrable(): bool
    {
        return $this->lettrable;
    }

    public function setLettrable(bool $lettrable): static
    {
        $this->lettrable = $lettrable;

        return $this;
    }

    public function getCounterpartNumber(): ?string
    {
        return $this->counterpartNumber;
    }

    public function setCounterpartNumber(?string $counterpartNumber): static
    {
        $this->counterpartNumber = $counterpartNumber;

        return $this;
    }

    public function getDefaultVatCode(): ?string
    {
        return $this->defaultVatCode;
    }

    public function setDefaultVatCode(?string $defaultVatCode): static
    {
        $this->defaultVatCode = $defaultVatCode;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }
}
