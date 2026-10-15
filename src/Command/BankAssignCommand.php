<?php

namespace Wexample\SymfonyAccounting\Command;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\BankAccountRepository;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\Service\Bank\BankLineAssignmentService;
use Wexample\SymfonyHelpers\Service\BundleService;

/**
 * Points the lines of a shared account to a ledger, all at once: those whose
 * label matches, within dates. Lines already explained are left where they are.
 */
class BankAssignCommand extends AbstractAccountingCommand
{
    public function __construct(
        BundleService $bundleService,
        LedgerRepository $ledgerRepository,
        FiscalYearRepository $fiscalYearRepository,
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly BankTransactionRepository $transactionRepository,
        private readonly BankLineAssignmentService $assignmentService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct($bundleService, $ledgerRepository, $fiscalYearRepository);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Points lines of a shared bank account to a ledger.')
            ->addArgument('bank-account', InputArgument::REQUIRED, 'Id of any share of the account')
            ->addArgument('ledger', InputArgument::REQUIRED, 'Ledger id or external reference')
            ->addOption('match', null, InputOption::VALUE_REQUIRED, 'Regular expression on the label, case-insensitive')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First date, Y-m-d')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last date, Y-m-d')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count without moving');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $id = (string) $input->getArgument('bank-account');
        $bankAccount = (Uuid::isValid($id) ? $this->bankAccountRepository->find(Uuid::fromString($id)) : null)
            ?? throw new AccountingException(sprintf('No bank account "%s".', $id));
        $ledger = $this->findLedger($input->getArgument('ledger'));
        $pattern = $input->getOption('match') ? '~'.str_replace('~', '\~', $input->getOption('match')).'~iu' : null;
        $from = $input->getOption('from') ? new DateTimeImmutable($input->getOption('from')) : null;
        $to = $input->getOption('to') ? new DateTimeImmutable($input->getOption('to')) : null;
        $moved = 0;

        foreach ($this->bankAccountRepository->findShares($bankAccount) as $share) {
            if ($share->getLedger() === $ledger) {
                continue;
            }

            /** @var BankTransaction $line */
            foreach ($this->transactionRepository->findPointable($share, '', $from, $to) as $line) {
                if ($pattern && ! preg_match($pattern, $line->getLabel())) {
                    continue;
                }

                $input->getOption('dry-run') || $this->assignmentService->assign($line, $ledger);
                ++$moved;
            }
        }

        $this->entityManager->flush();
        $output->writeln(sprintf('%d line(s) pointed to %s; those already explained stay where they are.', $moved, $ledger->getName()));

        return self::SUCCESS;
    }
}
