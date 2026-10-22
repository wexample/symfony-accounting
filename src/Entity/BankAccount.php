<?php

namespace Wexample\SymfonyAccounting\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\Pseudocode\Attribute\PseudocodeExport;
use Wexample\SymfonyAccounting\Entity\Traits\BelongsToLedgerTrait;
use Wexample\SymfonyAccounting\Interface\BelongsToLedgerInterface;
use Wexample\SymfonyAccounting\Repository\BankAccountRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyOrganization\Entity\Organization;

/**
 * An account holding the ledger's money: a bank account, a cash box, or the
 * balance a payment provider keeps (`provider` names it, e.g. "stripe", so its
 * movements can be imported through symfony-remote-payment).
 *
 * The account at the bank belongs to its owner, an organization of the
 * directory — a company, or a person. When the owner's account also carries
 * the money of other organizations (a personal account a sole trader is paid
 * on), each ledger concerned has its own share of it: an account with the
 * same owner and IBAN, booked on its own chart, holding the lines pointed to
 * that ledger (BankLineAssignmentService). Lines are imported once, into any
 * share; the owner's share holds what is pointed to nobody else.
 */
#[PseudocodeExport(inherited: true)]
#[ORM\Entity(repositoryClass: BankAccountRepository::class)]
#[ORM\Table(name: 'accounting_bank_account')]
class BankAccount extends AbstractEntity implements BelongsToLedgerInterface
{
    use BelongsToLedgerTrait;

    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $label;

    #[ORM\Column(type: Types::STRING, length: 34, nullable: true)]
    protected ?string $iban = null;

    #[ORM\Column(type: Types::STRING, length: 11, nullable: true)]
    protected ?string $bic = null;

    /** The holder's name as the bank prints it. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $holder = null;

    /** Who holds the account; the ledger's organization when null. */
    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    protected ?Organization $owner = null;

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

    public function getOwner(): Organization
    {
        return $this->owner ?? $this->getLedger()->getOrganization();
    }

    public function setOwner(?Organization $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * The owner's own share: the one its lines go back to.
     */
    public function isOwnerShare(): bool
    {
        return $this->getOwner() === $this->getLedger()->getOrganization();
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
