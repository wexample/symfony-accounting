<?php

namespace Wexample\SymfonyAccounting\Tests\Fixtures\App\Payable;

use Wexample\SymfonyMoney\Entity\Traits\HasPriceDiscountTrait;
use Wexample\SymfonyMoney\Entity\Traits\HasPriceVatTrait;
use Wexample\SymfonyMoney\Entity\Traits\HasQuantityTrait;
use Wexample\SymfonyMoney\Entity\Traits\PricedChildTrait;
use Wexample\SymfonyMoney\Interface\DiscountedInterface;
use Wexample\SymfonyMoney\Interface\PricedLineInterface;
use Wexample\SymfonyMoney\Interface\PricedParentInterface;

class TestSaleLine implements PricedLineInterface, DiscountedInterface
{
    use PricedChildTrait;
    use HasQuantityTrait;
    use HasPriceVatTrait;
    use HasPriceDiscountTrait;

    private ?TestSale $sale = null;

    public function __construct(
        private readonly string $title,
        int $priceRaw,
        int $quantity = 1,
        int $vat = 2100,
        private readonly bool $goods = false,
        private readonly ?string $accountNumber = null,
    ) {
        $this->priceRaw = $priceRaw;
        $this->quantity = $quantity;
        $this->priceVat = $vat;
    }

    public function setSale(TestSale $sale): void
    {
        $this->sale = $sale;
        $this->updatePriceTotal();
    }

    public function getPriceParent(): ?PricedParentInterface
    {
        return $this->sale;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function isGoods(): bool
    {
        return $this->goods;
    }

    public function getAccountNumber(): ?string
    {
        return $this->accountNumber;
    }
}
