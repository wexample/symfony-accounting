<?php

namespace Wexample\SymfonyAccounting\Tests\Fixtures\App\Payable;

use Wexample\SymfonyGeo\Entity\AddressSnapshot;
use Wexample\SymfonyMoney\Entity\Traits\HasPriceDiscountTrait;
use Wexample\SymfonyMoney\Entity\Traits\PricedParentTrait;
use Wexample\SymfonyMoney\Interface\DiscountedInterface;
use Wexample\SymfonyMoney\Interface\PricedParentInterface;
use Wexample\SymfonyPayment\Interface\BuyerIdentifiedInterface;
use Wexample\SymfonyPayment\Interface\PayableInterface;

/**
 * A sale of a package accounting never heard of, as a shop's cart is one.
 */
class TestSale implements PayableInterface, PricedParentInterface, DiscountedInterface, BuyerIdentifiedInterface
{
    use PricedParentTrait;
    use HasPriceDiscountTrait;

    /** @var list<TestSaleLine> */
    public array $lines = [];

    public ?string $buyerName = 'Ada Lovelace';

    public ?string $buyerCompanyName = null;

    public ?string $buyerEmail = 'ada@example.com';

    public ?string $buyerVatNumber = null;

    public ?AddressSnapshot $buyerAddress = null;

    public function __construct(public readonly string $id)
    {
    }

    public function addLine(TestSaleLine $line): TestSaleLine
    {
        $this->lines[] = $line;
        $line->setSale($this);

        return $line;
    }

    public static function getPayableType(): string
    {
        return 'test_sale';
    }

    public function getPayableId(): string
    {
        return $this->id;
    }

    public function getPayableAmount(): int
    {
        return $this->calcPriceFinal();
    }

    public function getPayableCurrencyCode(): string
    {
        return 'EUR';
    }

    public function getPayableDescription(): ?string
    {
        return 'Sale '.$this->id;
    }

    public function getPricedChildren(): iterable
    {
        return $this->lines;
    }

    public function getBuyerName(): ?string
    {
        return $this->buyerName;
    }

    public function getBuyerCompanyName(): ?string
    {
        return $this->buyerCompanyName;
    }

    public function getBuyerEmail(): ?string
    {
        return $this->buyerEmail;
    }

    public function getBuyerPhone(): ?string
    {
        return '+32 2 000 00 00';
    }

    public function getBuyerVatNumber(): ?string
    {
        return $this->buyerVatNumber;
    }

    public function getBuyerAddress(): ?AddressSnapshot
    {
        return $this->buyerAddress;
    }
}
