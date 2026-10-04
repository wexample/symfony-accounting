<?php

namespace Wexample\SymfonyAccounting\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Entity\Traits\HasLegalIdentityTrait;
use Wexample\SymfonyAccounting\Enum\VatPeriodicity;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyGeo\Interface\PostalAddressInterface;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * The books of one legal entity: a company, a sole trader, an association.
 *
 * An accounting firm keeps one ledger per client; an app keeping its own books
 * has a single one. Its country selects the jurisdiction (chart of accounts, VAT,
 * legal mentions); its identity is printed on the invoices it emits.
 * `externalReference` links it to the host's organization.
 */
#[ORM\Entity(repositoryClass: LedgerRepository::class)]
#[ORM\Table(name: 'accounting_ledger')]
class Ledger extends AbstractEntity implements PostalAddressInterface
{
    use HasLegalIdentityTrait;

    /** "SAS", "SRL", "EI", "ASBL"… as the jurisdiction knows them. */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    protected ?string $legalForm = null;

    /** Free mentions printed under the identity: share capital, RCS city… */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $legalMentions = null;

    #[ORM\Column(type: Types::STRING, length: 3)]
    protected string $currencyCode = 'EUR';

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $website = null;

    /** Whether the entity charges VAT at all (false: franchise / exempt). */
    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $vatSubject = true;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: VatPeriodicity::class)]
    protected VatPeriodicity $vatPeriodicity = VatPeriodicity::Quarterly;

    /**
     * VAT becomes due when the customer pays rather than when the invoice is emitted
     * (FR "TVA sur les encaissements", the default for services).
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $vatOnPayments = false;

    /** Jurisdiction-specific regime key, e.g. "fr_ca12", "fr_ca3", "be_normal". */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    protected ?string $vatRegime = null;

    /** Inserted in sale numbers: BIL-<prefix>2026-0001. */
    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    protected ?string $invoicePrefix = null;

    #[ORM\Column(type: Types::INTEGER)]
    protected int $paymentTermDays = 30;

    /** The account printed on invoices to be paid on. */
    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?BankAccount $defaultBankAccount = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    protected ?string $externalReference = null;

    /** Account numbers chosen for roles, overriding the jurisdiction defaults. */
    #[ORM\Column(type: Types::JSON)]
    protected array $accountRoles = [];

    #[ORM\Column(type: Types::JSON)]
    protected array $settings = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $dateCreated;

    public function __construct()
    {
        parent::__construct();
        $this->dateCreated = new DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getLegalForm(): ?string
    {
        return $this->legalForm;
    }

    public function setLegalForm(?string $legalForm): static
    {
        $this->legalForm = $legalForm;

        return $this;
    }

    public function getLegalMentions(): ?string
    {
        return $this->legalMentions;
    }

    public function setLegalMentions(?string $legalMentions): static
    {
        $this->legalMentions = $legalMentions;

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

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;

        return $this;
    }

    public function isVatSubject(): bool
    {
        return $this->vatSubject;
    }

    public function setVatSubject(bool $vatSubject): static
    {
        $this->vatSubject = $vatSubject;

        if (! $vatSubject) {
            $this->vatPeriodicity = VatPeriodicity::None;
        }

        return $this;
    }

    public function getVatPeriodicity(): VatPeriodicity
    {
        return $this->vatPeriodicity;
    }

    public function setVatPeriodicity(VatPeriodicity $vatPeriodicity): static
    {
        $this->vatPeriodicity = $vatPeriodicity;

        return $this;
    }

    public function isVatOnPayments(): bool
    {
        return $this->vatOnPayments;
    }

    public function setVatOnPayments(bool $vatOnPayments): static
    {
        $this->vatOnPayments = $vatOnPayments;

        return $this;
    }

    public function getVatRegime(): ?string
    {
        return $this->vatRegime;
    }

    public function setVatRegime(?string $vatRegime): static
    {
        $this->vatRegime = $vatRegime;

        return $this;
    }

    public function getInvoicePrefix(): ?string
    {
        return $this->invoicePrefix;
    }

    public function setInvoicePrefix(?string $invoicePrefix): static
    {
        $this->invoicePrefix = $invoicePrefix;

        return $this;
    }

    public function getPaymentTermDays(): int
    {
        return $this->paymentTermDays;
    }

    public function setPaymentTermDays(int $paymentTermDays): static
    {
        $this->paymentTermDays = $paymentTermDays;

        return $this;
    }

    public function getDefaultBankAccount(): ?BankAccount
    {
        return $this->defaultBankAccount;
    }

    public function setDefaultBankAccount(?BankAccount $defaultBankAccount): static
    {
        $this->defaultBankAccount = $defaultBankAccount;

        return $this;
    }

    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    public function setExternalReference(?string $externalReference): static
    {
        $this->externalReference = $externalReference;

        return $this;
    }

    /**
     * @return array<string, string> Role value → account number.
     */
    public function getAccountRoles(): array
    {
        return $this->accountRoles;
    }

    public function setAccountRoles(array $accountRoles): static
    {
        $this->accountRoles = $accountRoles;

        return $this;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function setSettings(array $settings): static
    {
        $this->settings = $settings;

        return $this;
    }

    public function getDateCreated(): DateTimeImmutable
    {
        return $this->dateCreated;
    }
}
