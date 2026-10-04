<?php

namespace Wexample\SymfonyAccounting\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Enum\InvoiceRelationType;
use Wexample\SymfonyAccounting\Repository\InvoiceRelationRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A link between two documents: a quotation and its bill, a bill and its credit
 * note, a purchase and the sale re-billing it. `amount` says how much of the
 * source the link covers, when not all of it.
 */
#[ORM\Entity(repositoryClass: InvoiceRelationRepository::class)]
#[ORM\Table(name: 'accounting_invoice_relation')]
class InvoiceRelation extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Invoice $source;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Invoice $target;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: InvoiceRelationType::class)]
    protected InvoiceRelationType $type;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $amount = null;

    public function getSource(): Invoice
    {
        return $this->source;
    }

    public function setSource(Invoice $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getTarget(): Invoice
    {
        return $this->target;
    }

    public function setTarget(Invoice $target): static
    {
        $this->target = $target;

        return $this;
    }

    public function getType(): InvoiceRelationType
    {
        return $this->type;
    }

    public function setType(InvoiceRelationType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(?int $amount): static
    {
        $this->amount = $amount;

        return $this;
    }
}
