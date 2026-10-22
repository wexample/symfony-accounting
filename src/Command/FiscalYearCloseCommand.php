<?php

namespace Wexample\SymfonyAccounting\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearClosingService;
use Wexample\SymfonyHelpers\Service\BundleService;

class FiscalYearCloseCommand extends AbstractAccountingCommand
{
    public function __construct(
        BundleService $bundleService,
        LedgerRepository $ledgerRepository,
        FiscalYearRepository $fiscalYearRepository,
        private readonly FiscalYearClosingService $closingService,
    ) {
        parent::__construct($bundleService, $ledgerRepository, $fiscalYearRepository);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Checks, then closes a fiscal year: result, opening balances of the next one, lock.')
            ->addArgument('ledger', InputArgument::REQUIRED, 'Ledger id or external reference')
            ->addArgument('fiscal-year', InputArgument::REQUIRED, 'A year ("2026") or a date inside it')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only list what the checks find')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Close despite errors');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $io = new SymfonyStyle($input, $output);
        $ledger = $this->findLedger($input->getArgument('ledger'));
        $fiscalYear = $this->findFiscalYear($ledger, $input->getArgument('fiscal-year'));
        $report = $this->closingService->check($fiscalYear);

        foreach ($report->getFindings() as $finding) {
            $io->writeln(sprintf('[%s] %s %s', $finding->severity->value, $finding->code, json_encode($finding->parameters)));
        }

        if ($input->getOption('check')) {
            return self::SUCCESS;
        }

        $this->closingService->close($fiscalYear, (bool) $input->getOption('force'));
        $io->success(sprintf('Fiscal year %s closed, result %d.', $fiscalYear->getLabel(), $fiscalYear->getResult()));

        return self::SUCCESS;
    }
}
