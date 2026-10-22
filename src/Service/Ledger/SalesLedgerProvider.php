<?php

namespace Wexample\SymfonyAccounting\Service\Ledger;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;

/**
 * The ledger that sells (setting `sales_ledger`): it invoices the paid payables,
 * and its country decides the VAT charged at checkout.
 */
class SalesLedgerProvider
{
    public function __construct(
        private readonly LedgerRepository $ledgerRepository,
        #[Autowire(param: 'wexample_symfony_accounting.sales_ledger')]
        private readonly ?string $externalReference = null,
    ) {
    }

    /**
     * Null when none is set, or none bears its reference yet.
     */
    public function getLedger(): ?Ledger
    {
        return null === $this->externalReference ? null : $this->ledgerRepository->findOneByExternalReference($this->externalReference);
    }
}
