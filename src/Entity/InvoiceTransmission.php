<?php

namespace Wexample\SymfonyAccounting\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\Pseudocode\Attribute\PseudocodeExport;
use Wexample\SymfonyAccounting\Repository\InvoiceTransmissionRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyRemotePeppol\Enum\TransmissionStatus;

/**
 * A document sent over Peppol: through which access point, under which reference,
 * and where it stands. A failed attempt stays; sending again adds another.
 */
#[PseudocodeExport(inherited: true)]
#[ORM\Entity(repositoryClass: InvoiceTransmissionRepository::class)]
#[ORM\Table(name: 'accounting_invoice_transmission')]
#[ORM\UniqueConstraint(columns: ['provider', 'reference'])]
class InvoiceTransmission extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Invoice $invoice;

    #[ORM\Column(type: Types::STRING, length: 50)]
    protected string $provider;

    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $reference;

    /** Who it was sent to, `0208:0123456749`. */
    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $receiver;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: TransmissionStatus::class)]
    protected TransmissionStatus $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $message = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $dateSent;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $dateUpdated;

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(Invoice $invoice): static
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getReceiver(): string
    {
        return $this->receiver;
    }

    public function setReceiver(string $receiver): static
    {
        $this->receiver = $receiver;

        return $this;
    }

    public function getStatus(): TransmissionStatus
    {
        return $this->status;
    }

    public function setStatus(TransmissionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getDateSent(): DateTimeImmutable
    {
        return $this->dateSent;
    }

    public function setDateSent(DateTimeImmutable $dateSent): static
    {
        $this->dateSent = $dateSent;

        return $this;
    }

    public function getDateUpdated(): DateTimeImmutable
    {
        return $this->dateUpdated;
    }

    public function setDateUpdated(DateTimeImmutable $dateUpdated): static
    {
        $this->dateUpdated = $dateUpdated;

        return $this;
    }
}
