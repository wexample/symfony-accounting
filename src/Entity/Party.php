<?php

namespace Wexample\SymfonyAccounting\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Entity\Traits\BelongsToLedgerTrait;
use Wexample\SymfonyAccounting\Entity\Traits\HasLegalIdentityTrait;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
use Wexample\SymfonyGeo\Interface\PostalAddressInterface;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A third party of the ledger: customer, supplier, or both.
 *
 * Its auxiliary account codes (`C…` for the customer side, `F…`/`S…` for the
 * supplier side) are what accountants letter and what the FEC exports as
 * CompAuxNum. `externalReference` links it to the host's organization or user.
 */
#[ORM\Entity(repositoryClass: PartyRepository::class)]
#[ORM\Table(name: 'accounting_party')]
#[ORM\UniqueConstraint(columns: ['ledger_id', 'customer_code'])]
#[ORM\UniqueConstraint(columns: ['ledger_id', 'supplier_code'])]
class Party extends AbstractEntity implements PostalAddressInterface
{
    use BelongsToLedgerTrait;
    use HasLegalIdentityTrait;

    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $customer = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $supplier = false;

    /** A private person rather than a business (B2C rules, no VAT number). */
    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $individual = false;

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
        return $this->name;
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

    public function isIndividual(): bool
    {
        return $this->individual;
    }

    public function setIndividual(bool $individual): static
    {
        $this->individual = $individual;

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
