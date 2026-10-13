<?php

namespace Wexample\SymfonyAccounting\Tests\Fixtures\App\Payable;

use Wexample\SymfonyPayment\Interface\PayableInterface;
use Wexample\SymfonyPayment\Interface\PayableResolverInterface;

class TestSaleResolver implements PayableResolverInterface
{
    /** @var array<string, TestSale> */
    public array $sales = [];

    public function supports(string $payableType): bool
    {
        return TestSale::getPayableType() === $payableType;
    }

    public function resolve(
        string $payableType,
        string $payableId
    ): ?PayableInterface {
        return $this->sales[$payableId] ?? null;
    }
}
