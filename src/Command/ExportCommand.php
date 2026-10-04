<?php

namespace Wexample\SymfonyAccounting\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\Service\Exchange\LedgerExportService;
use Wexample\SymfonyHelpers\Service\BundleService;

class ExportCommand extends AbstractAccountingCommand
{
    public function __construct(
        BundleService $bundleService,
        LedgerRepository $ledgerRepository,
        FiscalYearRepository $fiscalYearRepository,
        private readonly LedgerExportService $exportService,
    ) {
        parent::__construct($bundleService, $ledgerRepository, $fiscalYearRepository);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Exports the books of a fiscal year (FEC, CSV entries, general ledger…).')
            ->addArgument('ledger', InputArgument::REQUIRED, 'Ledger id or external reference')
            ->addArgument('fiscal-year', InputArgument::REQUIRED, 'A year ("2026") or a date inside it')
            ->addArgument('format', InputArgument::OPTIONAL, 'Exporter key; lists them when missing')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Directory to write to', '.');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $ledger = $this->findLedger($input->getArgument('ledger'));
        $fiscalYear = $this->findFiscalYear($ledger, $input->getArgument('fiscal-year'));

        if (! $input->getArgument('format')) {
            foreach ($this->exportService->getChoices($fiscalYear) as $key => $label) {
                $output->writeln($key.': '.$label);
            }

            return self::SUCCESS;
        }

        $file = $this->exportService->export($fiscalYear, $input->getArgument('format'));
        $path = rtrim($input->getOption('output'), '/').'/'.$file->filename;
        file_put_contents($path, $file->content);
        $output->writeln('Written '.$path);

        return self::SUCCESS;
    }
}
