<?php

namespace Wexample\SymfonyAccounting\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Enum\VatKind;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyMoney\Entity\Traits\HasPriceDiscountTrait;
use Wexample\SymfonyMoney\Entity\Traits\HasPriceVatTrait;
use Wexample\SymfonyMoney\Entity\Traits\HasQuantityTrait;
use Wexample\SymfonyMoney\Entity\Traits\PricedChildTrait;
use Wexample\SymfonyMoney\Interface\DiscountedInterface;
use Wexample\SymfonyMoney\Interface\PricedChildInterface;
use Wexample\SymfonyMoney\Interface\PricedParentInterface;
use Wexample\SymfonyMoney\Interface\QuantifiedInterface;
use Wexample\SymfonyMoney\Interface\VatRatedInterface;

/**
 * A line of a document. The quantity is scaled by 100 (150 = 1.5), so days and
 * hours can be billed; `duration` keeps what was typed ("1h30") when the
 * quantity was given as a duration. VAT is per item.
 */
#[ORM\Entity]
#[ORM\Table(name: 'accounting_invoice_item')]
class InvoiceItem extends AbstractEntity implements PricedChildInterface, QuantifiedInterface, VatRatedInterface, DiscountedInterface
{
    use PricedChildTrait;
    use HasQuantityTrait;
    use HasPriceVatTrait;
    use HasPriceDiscountTrait;

    public const int QUANTITY_SCALE = 100;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ?Invoice $invoice = null;

    #[ORM\Column(type: Types::INTEGER)]
    protected int $position = 0;

    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    protected ?string $reference = null;

    #[ORM\Column(type: Types::STRING, length: 30, nullable: true)]
    protected ?string $duration = null;

    /** "unit", "hour", "day", "month"… printed next to the quantity. */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    protected ?string $unit = null;

    /** Goods rather than services: changes the VAT rules abroad. */
    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $goods = false;

    #[ORM\Column(type: Types::STRING, length: 30, nullable: true)]
    protected ?string $vatCode = null;

    #[ORM\Column(type: Types::STRING, length: 30, nullable: true, enumType: VatKind::class)]
    protected ?VatKind $vatKind = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    protected ?string $accountNumber = null;

    public function __construct()
    {
        parent::__construct();
        $this->quantity = self::QUANTITY_SCALE;
    }

    public function getQuantityScale(): int
    {
        return self::QUANTITY_SCALE;
    }

    public function getPriceParent(): ?PricedParentInterface
    {
        return $this->invoice;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): static
    {
        $this->invoice = $invoice;

        return $this->updatePriceTotal();
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    public function getDuration(): ?string
    {
        return $this->duration;
    }

    public function setDuration(?string $duration): static
    {
        $this->duration = $duration;

        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function isGoods(): bool
    {
        return $this->goods;
    }

    public function setGoods(bool $goods): static
    {
        $this->goods = $goods;

        return $this;
    }

    public function getVatCode(): ?string
    {
        return $this->vatCode;
    }

    public function getVatKind(): ?VatKind
    {
        return $this->vatKind;
    }

    public function setVat(
        ?string $vatCode,
        ?VatKind $vatKind
    ): static {
        $this->vatCode = $vatCode;
        $this->vatKind = $vatKind;

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

    /**
     * A copy for another document: same content, no invoice yet.
     */
    public function duplicate(): self
    {
        $copy = (new self())
            ->setTitle($this->title)
            ->setDescription($this->description)
            ->setReference($this->reference)
            ->setDuration($this->duration)
            ->setUnit($this->unit)
            ->setGoods($this->goods)
            ->setAccountNumber($this->accountNumber);
        $copy->priceRaw = $this->priceRaw;
        $copy->priceVat = $this->priceVat;
        $copy->priceOverridden = $this->priceOverridden;
        $copy->priceDiscount = $this->priceDiscount;
        $copy->priceDiscountUnit = $this->priceDiscountUnit;
        $copy->quantity = $this->quantity;

        return $copy->updatePriceTotal();
    }
}
