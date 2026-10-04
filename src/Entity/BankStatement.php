<?php

namespace Wexample\SymfonyAccounting\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Repository\BankStatementRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * The balance the bank states for an account at the end of a day. Reconciliation
 * compares it with the previous statement plus the transactions in between.
 */
#[ORM\Entity(repositoryClass: BankStatementRepository::class)]
#[ORM\Table(name: 'accounting_bank_statement')]
#[ORM\UniqueConstraint(columns: ['bank_account_id', 'date'])]
class BankStatement extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected BankAccount $bankAccount;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    protected DateTimeImmutable $date;

    #[ORM\Column(type: Types::BIGINT)]
    protected string $balance;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $reference = null;

    public function getBankAccount(): BankAccount
    {
        return $this->bankAccount;
    }

    public function setBankAccount(BankAccount $bankAccount): static
    {
        $this->bankAccount = $bankAccount;

        return $this;
    }

    public function getDate(): DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(DateTimeImmutable $date): static
    {
        $this->date = $date->setTime(0, 0);

        return $this;
    }

    public function getBalance(): int
    {
        return (int) $this->balance;
    }

    public function setBalance(int $balance): static
    {
        $this->balance = (string) $balance;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }
}
