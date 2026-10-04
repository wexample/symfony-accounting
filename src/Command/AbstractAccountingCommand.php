<?php

namespace Wexample\SymfonyAccounting\Command;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\WexampleSymfonyAccountingBundle;
use Wexample\SymfonyHelpers\Command\AbstractBundleCommand;
use Wexample\SymfonyHelpers\Service\BundleService;

abstract class AbstractAccountingCommand extends AbstractBundleCommand
{
    public function __construct(
        BundleService $bundleService,
        protected readonly LedgerRepository $ledgerRepository,
        protected readonly FiscalYearRepository $fiscalYearRepository,
    ) {
        parent::__construct($bundleService);
    }

    public static function getBundleClassName(): string
    {
        return WexampleSymfonyAccountingBundle::class;
    }

    /**
     * A ledger by id, or by the host's external reference.
     */
    protected function findLedger(string $reference): Ledger
    {
        $ledger = Uuid::isValid($reference)
            ? $this->ledgerRepository->find(Uuid::fromString($reference))
            : $this->ledgerRepository->findOneByExternalReference($reference);

        return $ledger ?? throw new AccountingException(sprintf('No ledger "%s".', $reference));
    }

    /**
     * The fiscal year covering a date ("2026-06-30"), or named by its year ("2026").
     */
    protected function findFiscalYear(
        Ledger $ledger,
        string $reference
    ): FiscalYear {
        $date = preg_match('/^\d{4}$/', $reference)
            ? new DateTimeImmutable($reference.'-12-31')
            : new DateTimeImmutable($reference);

        return $this->fiscalYearRepository->findForDate($ledger, $date)
            ?? throw new AccountingException(sprintf('No fiscal year covers %s.', $date->format('Y-m-d')));
    }
}
