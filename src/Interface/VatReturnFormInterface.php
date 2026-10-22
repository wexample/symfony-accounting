<?php

namespace Wexample\SymfonyAccounting\Interface;

use Wexample\SymfonyAccounting\Class\VatReturn;
use Wexample\SymfonyAccounting\Entity\Ledger;

/**
 * A national VAT form (FR CA3, CA12; BE periodic declaration): places the totals
 * of a VatReturn in the form's boxes. Data only; rendering belongs to pdf-factory
 * or to the tax administration's upload format.
 */
interface VatReturnFormInterface
{
    /**
     * "fr_ca3", "fr_ca12", "be_periodic"…
     */
    public function getKey(): string;

    public function supports(Ledger $ledger): bool;

    /**
     * @return array<string, int> Box code → amount in minor units.
     */
    public function fill(VatReturn $vatReturn): array;
}
