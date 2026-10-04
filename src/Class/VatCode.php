<?php

namespace Wexample\SymfonyAccounting\Class;

use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\VatKind;

/**
 * A tax code: one rate in one tax situation and direction ("sale, domestic, 21 %").
 *
 * Entry lines carry the code, so that a VAT return only has to sum bases and
 * taxes per code, then place each code in the boxes of the national form.
 * A self-assessed code (reverse charge, intra-EU acquisition) books the tax
 * twice: due on `selfAssessedRole`, deductible on `accountRole`.
 */
final readonly class VatCode
{
    public function __construct(
        public string $code,
        public int $rate,
        public InvoiceDirection $direction,
        public VatKind $kind,
        public ?AccountRole $accountRole,
        public ?AccountRole $selfAssessedRole = null,
        public ?string $label = null,
    ) {
    }

    public function isSelfAssessed(): bool
    {
        return null !== $this->selfAssessedRole;
    }

    /**
     * Whether the invoice itself carries the tax (the customer pays it to the seller).
     */
    public function isCharged(): bool
    {
        return VatKind::Domestic === $this->kind && $this->rate > 0;
    }
}
