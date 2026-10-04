<?php

namespace Wexample\SymfonyAccounting\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\Service\Invoice\DunningService;
use Wexample\SymfonyHelpers\Service\BundleService;

/**
 * Lists the reminders due; with --record, records them, which dispatches the
 * event the host sends them on.
 */
class DunningCommand extends AbstractAccountingCommand
{
    public function __construct(
        BundleService $bundleService,
        LedgerRepository $ledgerRepository,
        FiscalYearRepository $fiscalYearRepository,
        private readonly DunningService $dunningService,
    ) {
        parent::__construct($bundleService, $ledgerRepository, $fiscalYearRepository);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Lists, and with --record sends, the payment reminders due.')
            ->addArgument('ledger', InputArgument::REQUIRED, 'Ledger id or external reference')
            ->addOption('record', null, InputOption::VALUE_NONE);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $notices = $this->dunningService->findDue($this->findLedger($input->getArgument('ledger')));

        (new SymfonyStyle($input, $output))->table(
            ['Document', 'Party', 'Days late', 'Level', 'Remaining'],
            array_map(fn ($notice) => [
                $notice->invoice->getNumber(),
                $notice->invoice->getParty()?->getName(),
                $notice->daysLate,
                $notice->level,
                $notice->remaining,
            ], $notices)
        );

        if ($input->getOption('record')) {
            foreach ($notices as $notice) {
                $this->dunningService->record($notice);
            }
        }

        return self::SUCCESS;
    }
}
