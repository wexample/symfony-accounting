<?php

namespace Wexample\SymfonyAccounting\Command;

use DateTimeImmutable;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\Service\Vat\VatPeriodService;
use Wexample\SymfonyAccounting\Service\Vat\VatReturnService;
use Wexample\SymfonyHelpers\Service\BundleService;

class VatReturnCommand extends AbstractAccountingCommand
{
    public function __construct(
        BundleService $bundleService,
        LedgerRepository $ledgerRepository,
        FiscalYearRepository $fiscalYearRepository,
        private readonly VatPeriodService $periodService,
        private readonly VatReturnService $returnService,
    ) {
        parent::__construct($bundleService, $ledgerRepository, $fiscalYearRepository);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Computes the VAT return of the period containing a date, and fills the national forms.')
            ->addArgument('ledger', InputArgument::REQUIRED, 'Ledger id or external reference')
            ->addArgument('date', InputArgument::REQUIRED, 'A date in the period')
            ->addOption('settle', null, InputOption::VALUE_NONE, 'Book the settlement entry');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $io = new SymfonyStyle($input, $output);
        $ledger = $this->findLedger($input->getArgument('ledger'));
        [$from, $to] = $this->periodService->getPeriodFor($ledger, new DateTimeImmutable($input->getArgument('date')));
        $return = $this->returnService->compute($ledger, $from, $to);

        $io->title(sprintf('VAT %s → %s', $from->format('Y-m-d'), $to->format('Y-m-d')));
        $io->table(['Code', 'Base', 'Due', 'Deductible'], array_map(
            fn ($line) => [$line->code, $line->base, $line->due, $line->deductible],
            array_values($return->lines)
        ));
        $io->writeln(sprintf('Previous credit %d, deposits %d, net %d.', $return->previousCredit, $return->deposits, $return->getNet()));

        foreach ($this->returnService->fillForms($return) as $form => $boxes) {
            $io->section($form);
            $io->table(['Box', 'Amount'], array_map(fn ($box, $amount) => [$box, $amount], array_keys($boxes), $boxes));
        }

        if ($input->getOption('settle')) {
            $this->returnService->settle($return);
            $io->success('Settlement booked.');
        }

        return self::SUCCESS;
    }
}
