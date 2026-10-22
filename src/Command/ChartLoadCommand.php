<?php

namespace Wexample\SymfonyAccounting\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;
use Wexample\SymfonyHelpers\Service\BundleService;

class ChartLoadCommand extends AbstractAccountingCommand
{
    public function __construct(
        BundleService $bundleService,
        LedgerRepository $ledgerRepository,
        FiscalYearRepository $fiscalYearRepository,
        private readonly ChartService $chartService,
    ) {
        parent::__construct($bundleService, $ledgerRepository, $fiscalYearRepository);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Loads a chart of accounts dataset into a ledger; existing accounts are kept.')
            ->addArgument('ledger', InputArgument::REQUIRED, 'Ledger id or external reference')
            ->addArgument('dataset', InputArgument::OPTIONAL, 'Dataset key; the jurisdiction default otherwise');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $count = $this->chartService->loadDataset($this->findLedger($input->getArgument('ledger')), $input->getArgument('dataset'));
        $output->writeln(sprintf('%d account(s) created.', $count));

        return self::SUCCESS;
    }
}
