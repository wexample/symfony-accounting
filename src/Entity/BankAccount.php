<?php

namespace Wexample\SymfonyAccounting\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Entity\Traits\BelongsToLedgerTrait;
use Wexample\SymfonyAccounting\Repository\BankAccountRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * An account holding the ledger's money: a bank account, a cash box, or the
 * balance a payment provider keeps (`provider` names it, e.g. "stripe", so its
 * movements can be imported through symfony-remote-payment).
 */
#[ORM\Entity(repositoryClass: BankAccountRepository::class)]
#[ORM\Table(name: 'accounting_bank_account')]
class BankAccount extends AbstractEntity
{
    use BelongsToLedgerTrait;

    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $label;

    #[ORM\Column(type: Types::STRING, length: 34, nullable: true)]
    protected ?string $iban = null;

    #[ORM\Column(type: Types::STRING, length: 11, nullable: true)]
    protected ?string $bic = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $holder = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $bankName = null;

    /** The ledger account it is booked on: "512", "5121", "517"… */
    #[ORM\Column(type: Types::STRING, length: 20)]
    protected string $accountNumber;

    /** The journal its movements are booked in ("BQ2"…); the ledger's bank journal when null. */
    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    protected ?string $journalCode = null;

    #[ORM\Column(type: Types::STRING, length: 3)]
    protected string $currencyCode = 'EUR';

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    protected ?string $provider = null;

    /** Local details some countries print on invoices (FR RIB: bank, branch, key…). */
    #[ORM\Column(type: Types::JSON)]
    protected array $localDetails = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $dateLastImport = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $active = true;

    public function __toString(): string
    {
        return $this->label;
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

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): static
    {
        $this->iban = null === $iban ? null : strtoupper(str_replace(' ', '', $iban));

        return $this;
    }

    public function getBic(): ?string
    {
        return $this->bic;
    }

    public function setBic(?string $bic): static
    {
        $this->bic = $bic;

        return $this;
    }

    public function getHolder(): ?string
    {
        return $this->holder;
    }

    public function setHolder(?string $holder): static
    {
        $this->holder = $holder;

        return $this;
    }

    public function getBankName(): ?string
    {
        return $this->bankName;
    }

    public function setBankName(?string $bankName): static
    {
        $this->bankName = $bankName;

        return $this;
    }

    public function getAccountNumber(): string
    {
        return $this->accountNumber;
    }

    public function setAccountNumber(string $accountNumber): static
    {
        $this->accountNumber = $accountNumber;

        return $this;
    }

    public function getJournalCode(): ?string
    {
        return $this->journalCode;
    }

    public function setJournalCode(?string $journalCode): static
    {
        $this->journalCode = $journalCode;

        return $this;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(string $currencyCode): static
    {
        $this->currencyCode = strtoupper($currencyCode);

        return $this;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getLocalDetails(): array
    {
        return $this->localDetails;
    }

    public function setLocalDetails(array $localDetails): static
    {
        $this->localDetails = $localDetails;

        return $this;
    }

    public function getDateLastImport(): ?DateTimeImmutable
    {
        return $this->dateLastImport;
    }

    public function setDateLastImport(?DateTimeImmutable $dateLastImport): static
    {
        $this->dateLastImport = $dateLastImport;

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
