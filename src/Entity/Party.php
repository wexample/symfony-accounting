<?php

namespace Wexample\SymfonyAccounting\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Wexample\Pseudocode\Attribute\PseudocodeExport;
use Wexample\SymfonyAccounting\Entity\Traits\BelongsToLedgerTrait;
use Wexample\SymfonyAccounting\Interface\BelongsToLedgerInterface;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyOrganization\Entity\Organization;
use Wexample\SymfonyOrganization\Entity\Traits\DelegatesLegalIdentityTrait;
use Wexample\SymfonyOrganization\Interface\HasOrganizationInterface;
use Wexample\SymfonyOrganization\Interface\LegalIdentityInterface;

/**
 * A third party of the ledger: customer, supplier, or both — an organization
 * of the directory, as this ledger trades with it.
 *
 * Who it is (name, identifiers, VAT number, address) is its organization's,
 * read through it; what is the ledger's own is here: its auxiliary account
 * codes (`C…` for the customer side, `F…`/`S…` for the supplier side) — what
 * accountants letter and what the FEC exports as CompAuxNum —, its default
 * account, its payment terms. `externalReference` links it to a record of the
 * host's (a user, a CRM contact).
 */
#[PseudocodeExport(inherited: true)]
#[ORM\Entity(repositoryClass: PartyRepository::class)]
#[ORM\Table(name: 'accounting_party')]
#[ORM\UniqueConstraint(columns: ['ledger_id', 'customer_code'])]
#[ORM\UniqueConstraint(columns: ['ledger_id', 'supplier_code'])]
#[ORM\UniqueConstraint(columns: ['ledger_id', 'organization_id'])]
class Party extends AbstractEntity implements LegalIdentityInterface, HasOrganizationInterface, BelongsToLedgerInterface
{
    use BelongsToLedgerTrait;
    use DelegatesLegalIdentityTrait;

    /** Validated with it: an identity typed through it follows its country's rules. */
    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\Valid]
    protected Organization $organization;

    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $customer = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $supplier = false;

    #[ORM\Column(type: Types::STRING, length: 30, nullable: true)]
    protected ?string $customerCode = null;

    #[ORM\Column(type: Types::STRING, length: 30, nullable: true)]
    protected ?string $supplierCode = null;

    /** Revenue (customer) or expense (supplier) account used by default on its documents. */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    protected ?string $defaultAccountNumber = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $paymentTermDays = null;

    #[ORM\Column(type: Types::STRING, length: 34, nullable: true)]
    protected ?string $iban = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    protected ?string $externalReference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $notes = null;

    public function __toString(): string
    {
        return $this->getName();
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function setOrganization(Organization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function isCustomer(): bool
    {
        return $this->customer;
    }

    public function setCustomer(bool $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function isSupplier(): bool
    {
        return $this->supplier;
    }

    public function setSupplier(bool $supplier): static
    {
        $this->supplier = $supplier;

        return $this;
    }

    public function getCustomerCode(): ?string
    {
        return $this->customerCode;
    }

    public function setCustomerCode(?string $customerCode): static
    {
        $this->customerCode = $customerCode;

        return $this;
    }

    public function getSupplierCode(): ?string
    {
        return $this->supplierCode;
    }

    public function setSupplierCode(?string $supplierCode): static
    {
        $this->supplierCode = $supplierCode;

        return $this;
    }

    public function getDefaultAccountNumber(): ?string
    {
        return $this->defaultAccountNumber;
    }

    public function setDefaultAccountNumber(?string $defaultAccountNumber): static
    {
        $this->defaultAccountNumber = $defaultAccountNumber;

        return $this;
    }

    public function getPaymentTermDays(): ?int
    {
        return $this->paymentTermDays;
    }

    public function setPaymentTermDays(?int $paymentTermDays): static
    {
        $this->paymentTermDays = $paymentTermDays;

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

    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    public function setExternalReference(?string $externalReference): static
    {
        $this->externalReference = $externalReference;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }
}
