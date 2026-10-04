<?php

namespace Wexample\SymfonyAccounting\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyAccounting\Repository\AllocationRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * What part of a bank line pays what: an invoice, or directly an account
 * (bank fees, an owner's withdrawal, a tax paid without an invoice).
 *
 * The amount carries the sign of the bank line. A pending allocation is a
 * matcher's proposal; an excluded one records a refusal, so the pair is never
 * proposed again.
 */
#[ORM\Entity(repositoryClass: AllocationRepository::class)]
#[ORM\Table(name: 'accounting_allocation')]
class Allocation extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: BankTransaction::class, inversedBy: 'allocations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected BankTransaction $transaction;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'allocations')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?Invoice $invoice = null;

    /** Booked straight on this account when there is no invoice. */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    protected ?string $accountNumber = null;

    #[ORM\ManyToOne(targetEntity: Party::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Party $party = null;

    /** VAT included in a direct allocation, in basis points (0: none). */
    #[ORM\Column(type: Types::INTEGER)]
    protected int $vatRate = 0;

    #[ORM\Column(type: Types::BIGINT)]
    protected string $amount;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: AllocationStatus::class)]
    protected AllocationStatus $status = AllocationStatus::Pending;

    /** Who made it: "manual", or the matcher's name. */
    #[ORM\Column(type: Types::STRING, length: 50)]
    protected string $origin = 'manual';

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $label = null;

    #[ORM\ManyToOne(targetEntity: JournalEntry::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?JournalEntry $entry = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $dateCreated;

    public function __construct()
    {
        parent::__construct();
        $this->dateCreated = new DateTimeImmutable();
    }

    public function getTransaction(): BankTransaction
    {
        return $this->transaction;
    }

    public function setTransaction(BankTransaction $transaction): static
    {
        $this->transaction = $transaction;

        return $this;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): static
    {
        $this->invoice = $invoice;
        $invoice?->addAllocation($this);

        return $this;
    }

    public function getAccountNumber(): ?string
    {
        return $this->accountNumber;
    }

    public function setAccountNumber(?string $accountNumber): static
    {
        $this->accountNumber = $accountNumber;

        return $this;
    }

    public function getParty(): ?Party
    {
        return $this->party ?? $this->invoice?->getParty();
    }

    public function setParty(?Party $party): static
    {
        $this->party = $party;

        return $this;
    }

    public function getVatRate(): int
    {
        return $this->vatRate;
    }

    public function setVatRate(int $vatRate): static
    {
        $this->vatRate = $vatRate;

        return $this;
    }

    public function getAmount(): int
    {
        return (int) $this->amount;
    }

    public function setAmount(int $amount): static
    {
        $this->amount = (string) $amount;

        return $this;
    }

    public function getStatus(): AllocationStatus
    {
        return $this->status;
    }

    public function setStatus(AllocationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function setOrigin(string $origin): static
    {
        $this->origin = $origin;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getEntry(): ?JournalEntry
    {
        return $this->entry;
    }

    public function setEntry(?JournalEntry $entry): static
    {
        $this->entry = $entry;

        return $this;
    }

    public function getDateCreated(): DateTimeImmutable
    {
        return $this->dateCreated;
    }
}
