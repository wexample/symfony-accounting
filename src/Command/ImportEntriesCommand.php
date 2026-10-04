<?php

namespace Wexample\SymfonyAccounting\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\Service\Exchange\EntryImportService;
use Wexample\SymfonyHelpers\Service\BundleService;

class ImportEntriesCommand extends AbstractAccountingCommand
{
    public function __construct(
        BundleService $bundleService,
        LedgerRepository $ledgerRepository,
        FiscalYearRepository $fiscalYearRepository,
        private readonly EntryImportService $importService,
    ) {
        parent::__construct($bundleService, $ledgerRepository, $fiscalYearRepository);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Imports the entries of another bookkeeping (FEC, CSV) into a ledger.')
            ->addArgument('ledger', InputArgument::REQUIRED, 'Ledger id or external reference')
            ->addArgument('file', InputArgument::REQUIRED)
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Importer key; detected otherwise')
            ->addOption('create-fiscal-years', null, InputOption::VALUE_NONE)
            ->addOption('fiscal-year-start-month', null, InputOption::VALUE_REQUIRED, '', '1');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $result = $this->importService->importContent(
            $this->findLedger($input->getArgument('ledger')),
            (string) file_get_contents($input->getArgument('file')),
            $input->getOption('format'),
            [
                'create_fiscal_years' => (bool) $input->getOption('create-fiscal-years'),
                'fiscal_year_start_month' => (int) $input->getOption('fiscal-year-start-month'),
            ]
        );

        $output->writeln(sprintf(
            '%d entries (%d lines); created: %d account(s), %d part(y/ies), %d journal(s), %d fiscal year(s).',
            $result->entries,
            $result->lines,
            $result->accountsCreated,
            $result->partiesCreated,
            $result->journalsCreated,
            $result->fiscalYearsCreated
        ));

        foreach ($result->errors as $entry => $error) {
            $output->writeln(sprintf('<error>%s: %s</error>', $entry, $error));
        }

        return $result->isComplete() ? self::SUCCESS : self::FAILURE;
    }
}
