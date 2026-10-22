<?php

namespace Wexample\SymfonyAccounting\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\Service\Asset\DepreciationService;
use Wexample\SymfonyHelpers\Service\BundleService;

class DepreciationCommand extends AbstractAccountingCommand
{
    public function __construct(
        BundleService $bundleService,
        LedgerRepository $ledgerRepository,
        FiscalYearRepository $fiscalYearRepository,
        private readonly DepreciationService $depreciationService,
    ) {
        parent::__construct($bundleService, $ledgerRepository, $fiscalYearRepository);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Books the depreciation of a fiscal year; assets already booked are skipped.')
            ->addArgument('ledger', InputArgument::REQUIRED, 'Ledger id or external reference')
            ->addArgument('fiscal-year', InputArgument::REQUIRED, 'A year ("2026") or a date inside it');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $ledger = $this->findLedger($input->getArgument('ledger'));
        $entries = $this->depreciationService->bookFiscalYear($this->findFiscalYear($ledger, $input->getArgument('fiscal-year')));
        $output->writeln(sprintf('%d depreciation entr(y/ies) booked.', count($entries)));

        return self::SUCCESS;
    }
}
