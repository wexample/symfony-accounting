<?php

namespace Wexample\SymfonyAccounting\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\Service\Bank\MatchingService;
use Wexample\SymfonyAccounting\Service\Ledger\LetteringService;
use Wexample\SymfonyHelpers\Service\BundleService;

class MatchCommand extends AbstractAccountingCommand
{
    public function __construct(
        BundleService $bundleService,
        LedgerRepository $ledgerRepository,
        FiscalYearRepository $fiscalYearRepository,
        private readonly MatchingService $matchingService,
        private readonly LetteringService $letteringService,
    ) {
        parent::__construct($bundleService, $ledgerRepository, $fiscalYearRepository);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Matches unexplained bank lines, then letters party accounts.')
            ->addArgument('ledger', InputArgument::REQUIRED, 'Ledger id or external reference')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Minimum confidence, 0 to 100', (string) MatchingService::DEFAULT_THRESHOLD)
            ->addOption('no-lettering', null, InputOption::VALUE_NONE);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $ledger = $this->findLedger($input->getArgument('ledger'));
        $output->writeln(sprintf('%d line(s) matched.', $this->matchingService->run($ledger, threshold: (int) $input->getOption('threshold'))));

        if (! $input->getOption('no-lettering')) {
            $output->writeln(sprintf('%d group(s) lettered.', $this->letteringService->letterLedger($ledger)));
        }

        return self::SUCCESS;
    }
}
