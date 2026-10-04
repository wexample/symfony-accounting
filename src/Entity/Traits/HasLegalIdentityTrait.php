<?php

namespace Wexample\SymfonyAccounting\Entity\Traits;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyGeo\Entity\Traits\HasPostalAddressTrait;

/**
 * Who someone is, legally: name, company identifier (SIREN/SIRET, BCE number),
 * VAT number, address. Shared by the ledger's own company and its parties.
 */
trait HasLegalIdentityTrait
{
    use HasPostalAddressTrait;

    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $name;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    protected ?string $legalIdentifier = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    protected ?string $vatNumber = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $email = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    protected ?string $phone = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getLegalIdentifier(): ?string
    {
        return $this->legalIdentifier;
    }

    public function setLegalIdentifier(?string $legalIdentifier): static
    {
        $this->legalIdentifier = $legalIdentifier;

        return $this;
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function setVatNumber(?string $vatNumber): static
    {
        $this->vatNumber = null === $vatNumber ? null : strtoupper(preg_replace('/[\s.\-]/', '', $vatNumber));

        return $this;
    }

    public function hasVatNumber(): bool
    {
        return null !== $this->vatNumber && '' !== $this->vatNumber;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }
}
