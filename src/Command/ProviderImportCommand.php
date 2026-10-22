<?php

namespace Wexample\SymfonyAccounting\Command;

use DateTimeImmutable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wexample\SymfonyAccounting\Repository\BankAccountRepository;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\Service\Bank\MatchingService;
use Wexample\SymfonyAccounting\Service\Bank\ProviderBalanceImporter;
use Wexample\SymfonyHelpers\Service\BundleService;

/**
 * Imports the balances of payment providers (Stripe…) into their bank accounts.
 * Meant for a cron; reports only what is new.
 */
class ProviderImportCommand extends AbstractAccountingCommand
{
    public function __construct(
        BundleService $bundleService,
        LedgerRepository $ledgerRepository,
        FiscalYearRepository $fiscalYearRepository,
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly ProviderBalanceImporter $importer,
        private readonly MatchingService $matchingService,
    ) {
        parent::__construct($bundleService, $ledgerRepository, $fiscalYearRepository);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Imports payment provider balances (Stripe…) into the ledgers.')
            ->addOption('ledger', null, InputOption::VALUE_REQUIRED, 'Only this ledger')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date; two days before the last import otherwise')
            ->addOption('match', null, InputOption::VALUE_NONE, 'Run the matching afterwards');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $ledger = $input->getOption('ledger') ? $this->findLedger($input->getOption('ledger')) : null;
        $from = $input->getOption('from') ? new DateTimeImmutable($input->getOption('from')) : null;

        foreach ($this->bankAccountRepository->findWithProvider($ledger) as $bankAccount) {
            $result = $this->importer->import($bankAccount, $from);
            $output->writeln(sprintf('%s (%s): %d new line(s).', $bankAccount->getLabel(), $bankAccount->getProvider(), $result->count()));

            if ($input->getOption('match')) {
                $this->matchingService->run($bankAccount->getLedger(), $bankAccount);
            }
        }

        return self::SUCCESS;
    }
}
