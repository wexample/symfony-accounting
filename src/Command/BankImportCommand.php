<?php

namespace Wexample\SymfonyAccounting\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\BankAccountRepository;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\Service\Bank\BankImportService;
use Wexample\SymfonyAccounting\Service\Bank\MatchingService;
use Wexample\SymfonyHelpers\Service\BundleService;

class BankImportCommand extends AbstractAccountingCommand
{
    public function __construct(
        BundleService $bundleService,
        LedgerRepository $ledgerRepository,
        FiscalYearRepository $fiscalYearRepository,
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly BankImportService $importService,
        private readonly MatchingService $matchingService,
    ) {
        parent::__construct($bundleService, $ledgerRepository, $fiscalYearRepository);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Imports a bank file into a bank account, without duplicates.')
            ->addArgument('bank-account', InputArgument::REQUIRED, 'Bank account id')
            ->addArgument('file', InputArgument::REQUIRED, 'CAMT, OFX, CODA, CSV…')
            ->addOption('parser', null, InputOption::VALUE_REQUIRED, 'Parser key; detected otherwise')
            ->addOption('options', null, InputOption::VALUE_REQUIRED, 'Parser options as JSON')
            ->addOption('match', null, InputOption::VALUE_NONE, 'Run the matching afterwards');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $id = $input->getArgument('bank-account');
        $bankAccount = Uuid::isValid($id) ? $this->bankAccountRepository->find(Uuid::fromString($id)) : null;

        if (! $bankAccount) {
            throw new AccountingException(sprintf('No bank account "%s".', $id));
        }

        $file = $input->getArgument('file');
        $result = $this->importService->importContent(
            $bankAccount,
            (string) file_get_contents($file),
            $input->getOption('parser'),
            json_decode($input->getOption('options') ?? '{}', true, flags: JSON_THROW_ON_ERROR),
            basename($file)
        );

        $output->writeln(sprintf('%d line(s) imported, %d duplicate(s) skipped, %d balance(s).', $result->count(), $result->duplicates, $result->balances));

        if ($input->getOption('match')) {
            $output->writeln(sprintf('%d line(s) matched.', $this->matchingService->run($bankAccount->getLedger(), $bankAccount)));
        }

        return self::SUCCESS;
    }
}
