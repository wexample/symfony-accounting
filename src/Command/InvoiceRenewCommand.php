<?php

namespace Wexample\SymfonyAccounting\Command;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyHelpers\Service\BundleService;

/**
 * Creates this month's documents from the recurring models (contracts,
 * subscriptions), once per model and month.
 */
class InvoiceRenewCommand extends AbstractAccountingCommand
{
    public function __construct(
        BundleService $bundleService,
        LedgerRepository $ledgerRepository,
        FiscalYearRepository $fiscalYearRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly InvoiceFactory $factory,
        private readonly InvoiceEmissionService $emissionService,
    ) {
        parent::__construct($bundleService, $ledgerRepository, $fiscalYearRepository);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Creates documents from recurring models for a month.')
            ->addArgument('date', InputArgument::OPTIONAL, 'Invoice date', 'today')
            ->addOption('emit', null, InputOption::VALUE_NONE, 'Emit them at once');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $date = new DateTimeImmutable($input->getArgument('date'));
        $month = $date->format('Y-m');
        $count = 0;

        foreach ($this->invoiceRepository->findBy(['status' => InvoiceStatus::Model]) as $model) {
            $done = array_filter(
                $this->invoiceRepository->findBy(['model' => $model]),
                fn ($invoice) => $invoice->getDateInvoice()->format('Y-m') === $month
            );

            if ([] !== $done) {
                continue;
            }

            $invoice = $this->factory->createFromModel($model, $date);
            $this->entityManager->flush();

            if ($input->getOption('emit')) {
                $this->emissionService->emit($invoice);
            }

            ++$count;
        }

        $output->writeln(sprintf('%d document(s) created.', $count));

        return self::SUCCESS;
    }
}
