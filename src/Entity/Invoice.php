<?php

namespace Wexample\SymfonyAccounting\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Entity\Traits\BelongsToLedgerTrait;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Enum\VatKind;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyMoney\Entity\Traits\HasPriceCurrencyTrait;
use Wexample\SymfonyMoney\Entity\Traits\HasPriceDiscountTrait;
use Wexample\SymfonyMoney\Entity\Traits\PricedParentTrait;
use Wexample\SymfonyMoney\Interface\DiscountedInterface;
use Wexample\SymfonyMoney\Interface\PricedParentInterface;
use Wexample\SymfonyPayment\Interface\PayableInterface;

/**
 * A commercial document of the ledger: a bill, credit note, quotation, penalty,
 * receipt or pro forma, emitted to a customer (sale) or received from a supplier
 * (purchase).
 *
 * Amounts are always positive, credit notes included: the document type decides
 * the accounting side. Sales are numbered at emission only, without gaps
 * (InvoiceEmissionService); purchases keep the supplier's number.
 *
 * Once emitted, the document is frozen: the party and the issuer are copied into
 * snapshots, as the law wants the document to stay as it was sent.
 */
#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'accounting_invoice')]
#[ORM\Index(columns: ['ledger_id', 'status'])]
#[ORM\Index(columns: ['ledger_id', 'date_invoice'])]
class Invoice extends AbstractEntity implements PricedParentInterface, DiscountedInterface, PayableInterface
{
    use BelongsToLedgerTrait;
    use PricedParentTrait;
    use HasPriceDiscountTrait;
    use HasPriceCurrencyTrait;

    public const string PAYABLE_TYPE = 'invoice';

    #[ORM\Column(type: Types::STRING, length: 20, enumType: InvoiceType::class)]
    protected InvoiceType $type = InvoiceType::Bill;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: InvoiceDirection::class)]
    protected InvoiceDirection $direction = InvoiceDirection::Sale;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: InvoiceStatus::class)]
    protected InvoiceStatus $status = InvoiceStatus::Draft;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    protected ?string $number = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $title = null;

    /** Printed introduction. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $note = null;

    /** Internal comment, never printed. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: Party::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    protected ?Party $party = null;

    /** The party as it was at emission: name, identifiers, address. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    protected ?array $partySnapshot = null;

    /** The ledger's company as it was at emission. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    protected ?array $issuerSnapshot = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $dateCreated;

    /** The invoice date, which is also the accounting date. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    protected DateTimeImmutable $dateInvoice;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $dateEmitted = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $dateDue = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $datePaid = null;

    /** Service period, printed when set. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $periodStart = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $periodEnd = null;

    /** Quotation validity. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $dateValidUntil = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $paymentTermDays = null;

    /** Written off: what will never be paid. */
    #[ORM\Column(type: Types::INTEGER)]
    protected int $priceLoss = 0;

    /** Default revenue or expense account of its items. */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    protected ?string $accountNumber = null;

    /** The reference the payer should give (BE structured communication). */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    protected ?string $paymentReference = null;

    /** The stored document (PDF path or URL); purchases keep the supplier's file here. */
    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    protected ?string $documentPath = null;

    /** How it was created: "manual", "bank", "cart", "model", "import". */
    #[ORM\Column(type: Types::STRING, length: 20)]
    protected string $origin = 'manual';

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?self $model = null;

    /** @var Collection<int, InvoiceItem> */
    #[ORM\OneToMany(targetEntity: InvoiceItem::class, mappedBy: 'invoice', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    protected Collection $items;

    /** @var Collection<int, Allocation> */
    #[ORM\OneToMany(targetEntity: Allocation::class, mappedBy: 'invoice')]
    protected Collection $allocations;

    #[ORM\Column(type: Types::JSON)]
    protected array $metadata = [];

    public function __construct()
    {
        parent::__construct();
        $this->items = new ArrayCollection();
        $this->allocations = new ArrayCollection();
        $this->dateCreated = new DateTimeImmutable();
        $this->dateInvoice = $this->dateCreated->setTime(0, 0);
        $this->priceTotal = 0;
    }

    public function __toString(): string
    {
        return $this->number ?? ($this->title ?? $this->type->value);
    }

    public static function getPayableType(): string
    {
        return self::PAYABLE_TYPE;
    }

    public function getPayableId(): string
    {
        return (string) $this->getId();
    }

    public function getPayableAmount(): int
    {
        return $this->calcRemainingAmount();
    }

    public function getPayableCurrencyCode(): string
    {
        return $this->getCurrencyCode();
    }

    public function getPayableDescription(): ?string
    {
        return trim(($this->number ?? '').' '.($this->title ?? '')) ?: null;
    }

    public function getPricedChildren(): iterable
    {
        return $this->items;
    }

    public function getType(): InvoiceType
    {
        return $this->type;
    }

    public function setType(InvoiceType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getDirection(): InvoiceDirection
    {
        return $this->direction;
    }

    public function setDirection(InvoiceDirection $direction): static
    {
        $this->direction = $direction;

        return $this;
    }

    public function isSale(): bool
    {
        return InvoiceDirection::Sale === $this->direction;
    }

    public function isCreditNote(): bool
    {
        return InvoiceType::CreditNote === $this->type;
    }

    /**
     * +1 when this document brings money in, −1 when it takes money out:
     * a sale bill is paid by a positive bank line, a sale credit note by a negative one.
     */
    public function getPaymentSign(): int
    {
        return $this->direction->getPaymentSign() * ($this->isCreditNote() ? -1 : 1);
    }

    public function getStatus(): InvoiceStatus
    {
        return $this->status;
    }

    /**
     * @internal Use InvoiceWorkflow, which guards transitions.
     */
    public function setStatus(InvoiceStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function setNumber(?string $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getParty(): ?Party
    {
        return $this->party;
    }

    public function setParty(?Party $party): static
    {
        $this->party = $party;

        return $this;
    }

    public function getPartySnapshot(): ?array
    {
        return $this->partySnapshot;
    }

    public function setPartySnapshot(?array $partySnapshot): static
    {
        $this->partySnapshot = $partySnapshot;

        return $this;
    }

    public function getIssuerSnapshot(): ?array
    {
        return $this->issuerSnapshot;
    }

    public function setIssuerSnapshot(?array $issuerSnapshot): static
    {
        $this->issuerSnapshot = $issuerSnapshot;

        return $this;
    }

    public function getDateCreated(): DateTimeImmutable
    {
        return $this->dateCreated;
    }

    public function getDateInvoice(): DateTimeImmutable
    {
        return $this->dateInvoice;
    }

    public function setDateInvoice(DateTimeImmutable $dateInvoice): static
    {
        $this->dateInvoice = $dateInvoice->setTime(0, 0);

        return $this;
    }

    public function getDateEmitted(): ?DateTimeImmutable
    {
        return $this->dateEmitted;
    }

    public function setDateEmitted(?DateTimeImmutable $dateEmitted): static
    {
        $this->dateEmitted = $dateEmitted;

        return $this;
    }

    public function getDateDue(): ?DateTimeImmutable
    {
        return $this->dateDue;
    }

    public function setDateDue(?DateTimeImmutable $dateDue): static
    {
        $this->dateDue = $dateDue?->setTime(0, 0);

        return $this;
    }

    /**
     * The due date, or the invoice date plus the payment term.
     */
    public function calcDateDue(): DateTimeImmutable
    {
        return $this->dateDue ?? $this->dateInvoice->modify('+'.$this->getPaymentTermDays().' days');
    }

    public function getDatePaid(): ?DateTimeImmutable
    {
        return $this->datePaid;
    }

    public function setDatePaid(?DateTimeImmutable $datePaid): static
    {
        $this->datePaid = $datePaid?->setTime(0, 0);

        return $this;
    }

    public function getPeriodStart(): ?DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function getPeriodEnd(): ?DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function setPeriod(
        ?DateTimeImmutable $start,
        ?DateTimeImmutable $end
    ): static {
        $this->periodStart = $start?->setTime(0, 0);
        $this->periodEnd = $end?->setTime(0, 0);

        return $this;
    }

    public function getDateValidUntil(): ?DateTimeImmutable
    {
        return $this->dateValidUntil;
    }

    public function setDateValidUntil(?DateTimeImmutable $dateValidUntil): static
    {
        $this->dateValidUntil = $dateValidUntil?->setTime(0, 0);

        return $this;
    }

    public function getPaymentTermDays(): int
    {
        return $this->paymentTermDays
            ?? $this->party?->getPaymentTermDays()
            ?? $this->ledger->getPaymentTermDays();
    }

    public function setPaymentTermDays(?int $paymentTermDays): static
    {
        $this->paymentTermDays = $paymentTermDays;

        return $this;
    }

    public function getPriceLoss(): int
    {
        return $this->priceLoss;
    }

    public function setPriceLoss(int $priceLoss): static
    {
        $this->priceLoss = $priceLoss;

        return $this;
    }

    public function getAccountNumber(): ?string
    {
        return $this->accountNumber;
    }

    public function setAccountNumber(?string $accountNumber): static
    {
        $this->accountNumber = $accountNumber;

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

    public function getDocumentPath(): ?string
    {
        return $this->documentPath;
    }

    public function setDocumentPath(?string $documentPath): static
    {
        $this->documentPath = $documentPath;

        return $this;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function setOrigin(string $origin): static
    {
        $this->origin = $origin;

        return $this;
    }

    public function getModel(): ?self
    {
        return $this->model;
    }

    public function setModel(?self $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * @return Collection<int, InvoiceItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(InvoiceItem $item): static
    {
        if (! $this->items->contains($item)) {
            $item->setPosition($this->items->count());
            $this->items->add($item);
            $item->setInvoice($this);
        }

        return $this;
    }

    public function removeItem(InvoiceItem $item): static
    {
        $this->items->removeElement($item);

        return $this->updatePriceTotal();
    }

    /**
     * @return list<VatKind> The tax situations of its items, each once.
     */
    public function getVatKinds(): array
    {
        $kinds = [];

        foreach ($this->items as $item) {
            if ($item->getVatKind() && ! in_array($item->getVatKind(), $kinds, true)) {
                $kinds[] = $item->getVatKind();
            }
        }

        return $kinds;
    }

    /**
     * @return Collection<int, Allocation>
     */
    public function getAllocations(): Collection
    {
        return $this->allocations;
    }

    public function addAllocation(Allocation $allocation): static
    {
        if (! $this->allocations->contains($allocation)) {
            $this->allocations->add($allocation);
        }

        return $this;
    }

    /**
     * What should actually be paid: the final price minus what was written off.
     */
    public function calcAmountExpected(): int
    {
        return $this->calcPriceFinal() - $this->priceLoss;
    }

    /**
     * Amount paid so far, positive, through active allocations (or only validated ones).
     */
    public function calcPaidAmount(bool $validatedOnly = false): int
    {
        $paid = 0;

        foreach ($this->allocations as $allocation) {
            $counts = $validatedOnly
                ? AllocationStatus::Validated === $allocation->getStatus()
                : $allocation->getStatus()->isActive();

            if ($counts) {
                $paid += $allocation->getAmount() * $this->getPaymentSign();
            }
        }

        return $paid;
    }

    public function calcRemainingAmount(): int
    {
        return $this->calcAmountExpected() - $this->calcPaidAmount();
    }

    public function isFullyPaid(): bool
    {
        return $this->calcPaidAmount() >= $this->calcAmountExpected() && $this->calcAmountExpected() > 0;
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
}
