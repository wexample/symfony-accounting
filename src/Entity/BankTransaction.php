<?php

namespace Wexample\SymfonyAccounting\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A line of a bank statement: signed amount in minor units, + for money in.
 *
 * It is settled by allocations (to invoices or straight to accounts), or by a
 * transfer to another account of the ledger (`transferPeer`, the line of opposite
 * amount on the other account).
 *
 * `externalId` is the bank's or provider's own id when the format has one
 * (Stripe txn_…, CAMT AcctSvcrRef); imports deduplicate on it, or on
 * `fingerprint` (date, amount, label) otherwise.
 */
#[ORM\Entity(repositoryClass: BankTransactionRepository::class)]
#[ORM\Table(name: 'accounting_bank_transaction')]
#[ORM\UniqueConstraint(columns: ['bank_account_id', 'external_id'])]
#[ORM\Index(columns: ['bank_account_id', 'date'])]
#[ORM\Index(columns: ['fingerprint'])]
#[ORM\HasLifecycleCallbacks]
class BankTransaction extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected BankAccount $bankAccount;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    protected DateTimeImmutable $date;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $valueDate = null;

    #[ORM\Column(type: Types::TEXT)]
    protected string $label;

    #[ORM\Column(type: Types::BIGINT)]
    protected string $amount;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $externalId = null;

    #[ORM\Column(type: Types::STRING, length: 64)]
    protected string $fingerprint;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $counterpartyName = null;

    #[ORM\Column(type: Types::STRING, length: 34, nullable: true)]
    protected ?string $counterpartyIban = null;

    /** A payment reference given by the payer (structured communication, end-to-end id). */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $reference = null;

    /** The provider payment it settles (Stripe PaymentIntent), when known. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $paymentReference = null;

    #[ORM\OneToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?self $transferPeer = null;

    /** @var Collection<int, Allocation> */
    #[ORM\OneToMany(targetEntity: Allocation::class, mappedBy: 'transaction', cascade: ['persist'], orphanRemoval: true)]
    protected Collection $allocations;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    protected ?string $importBatch = null;

    #[ORM\Column(type: Types::JSON)]
    protected array $metadata = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $dateCreated;

    public function __construct()
    {
        parent::__construct();
        $this->allocations = new ArrayCollection();
        $this->dateCreated = new DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->date->format('Y-m-d').' '.$this->label;
    }

    public static function buildFingerprint(
        DateTimeImmutable $date,
        int $amount,
        string $label
    ): string {
        return hash('sha256', $date->format('Y-m-d').'|'.$amount.'|'.mb_strtolower(preg_replace('/\s+/', ' ', trim($label))));
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function refreshFingerprint(): static
    {
        $this->fingerprint = self::buildFingerprint($this->date, $this->getAmount(), $this->label);

        return $this;
    }

    public function getBankAccount(): BankAccount
    {
        return $this->bankAccount;
    }

    public function setBankAccount(BankAccount $bankAccount): static
    {
        $this->bankAccount = $bankAccount;

        return $this;
    }

    public function getLedger(): Ledger
    {
        return $this->bankAccount->getLedger();
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

    public function getValueDate(): ?DateTimeImmutable
    {
        return $this->valueDate;
    }

    public function setValueDate(?DateTimeImmutable $valueDate): static
    {
        $this->valueDate = $valueDate?->setTime(0, 0);

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = trim($label);

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

    public function isCredit(): bool
    {
        return $this->getAmount() > 0;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function getCounterpartyName(): ?string
    {
        return $this->counterpartyName;
    }

    public function setCounterpartyName(?string $counterpartyName): static
    {
        $this->counterpartyName = $counterpartyName;

        return $this;
    }

    public function getCounterpartyIban(): ?string
    {
        return $this->counterpartyIban;
    }

    public function setCounterpartyIban(?string $counterpartyIban): static
    {
        $this->counterpartyIban = null === $counterpartyIban ? null : strtoupper(str_replace(' ', '', $counterpartyIban));

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

    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }

    public function setPaymentReference(?string $paymentReference): static
    {
        $this->paymentReference = $paymentReference;

        return $this;
    }

    public function getTransferPeer(): ?self
    {
        return $this->transferPeer;
    }

    /**
     * @internal Use AllocationService::linkTransfer(), which links both sides.
     */
    public function setTransferPeer(?self $transferPeer): static
    {
        $this->transferPeer = $transferPeer;

        return $this;
    }

    public function isTransfer(): bool
    {
        return null !== $this->transferPeer;
    }

    /**
     * @return Collection<int, Allocation>
     */
    public function getAllocations(): Collection
    {
        return $this->allocations;
    }

    /**
     * @return list<Allocation> Pending and validated ones.
     */
    public function getActiveAllocations(): array
    {
        return array_values($this->allocations->filter(fn (Allocation $a) => $a->getStatus()->isActive())->toArray());
    }

    public function addAllocation(Allocation $allocation): static
    {
        if (! $this->allocations->contains($allocation)) {
            $this->allocations->add($allocation);
            $allocation->setTransaction($this);
        }

        return $this;
    }

    /**
     * Signed amount covered by active allocations.
     */
    public function getAllocatedAmount(?AllocationStatus $status = null): int
    {
        $sum = 0;

        foreach ($this->allocations as $allocation) {
            if ($status ? $allocation->getStatus() === $status : $allocation->getStatus()->isActive()) {
                $sum += $allocation->getAmount();
            }
        }

        return $sum;
    }

    /**
     * Signed amount still to explain. Zero once allocated or transferred.
     */
    public function getUnallocatedAmount(): int
    {
        return $this->isTransfer() ? 0 : $this->getAmount() - $this->getAllocatedAmount();
    }

    public function isSettled(): bool
    {
        return 0 === $this->getUnallocatedAmount();
    }

    public function getImportBatch(): ?string
    {
        return $this->importBatch;
    }

    public function setImportBatch(?string $importBatch): static
    {
        $this->importBatch = $importBatch;

        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getDateCreated(): DateTimeImmutable
    {
        return $this->dateCreated;
    }
}
