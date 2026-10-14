<?php

namespace Wexample\SymfonyAccounting\Command;

use DateTimeImmutable;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\Service\Ledger\LedgerService;
use Wexample\SymfonyHelpers\Service\BundleService;
use Wexample\SymfonyOrganization\Entity\Organization;
use Wexample\SymfonyOrganization\Repository\OrganizationRepository;

/**
 * Opens books for an organization already in the directory: its journals and
 * the chart of its jurisdiction, as LedgerService::create() does — for an
 * application opening them from the command line, an import or a migration
 * of older books.
 */
class LedgerCreateCommand extends AbstractAccountingCommand
{
    public function __construct(
        BundleService $bundleService,
        LedgerRepository $ledgerRepository,
        FiscalYearRepository $fiscalYearRepository,
        private readonly LedgerService $ledgerService,
        private readonly OrganizationRepository $organizationRepository,
    ) {
        parent::__construct($bundleService, $ledgerRepository, $fiscalYearRepository);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Opens books for an organization of the directory: its journals and the chart of its country.')
            ->addArgument('organization', InputArgument::REQUIRED, 'Organization id or external reference')
            ->addOption('no-chart', null, InputOption::VALUE_NONE, 'Leave the chart of accounts empty')
            ->addOption('fiscal-year-start', null, InputOption::VALUE_REQUIRED, 'Open a first fiscal year of twelve months from this date (Y-m-d)')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Currency of the books', 'EUR');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $organization = $this->findOrganization($input->getArgument('organization'));

        if ($this->ledgerRepository->findOneBy(['organization' => $organization])) {
            throw new AccountingException(sprintf('"%s" already keeps books here.', $organization->getName()));
        }

        $start = $input->getOption('fiscal-year-start');
        $ledger = $this->ledgerService->create(
            $organization,
            loadChart: ! $input->getOption('no-chart'),
            fiscalYearStart: $start ? new DateTimeImmutable($start) : null,
            currencyCode: $input->getOption('currency'),
        );

        $output->writeln(sprintf('Books of "%s": %s', $organization->getName(), $ledger->getId()));

        return self::SUCCESS;
    }

    private function findOrganization(string $reference): Organization
    {
        $organization = Uuid::isValid($reference)
            ? $this->organizationRepository->find(Uuid::fromString($reference))
            : $this->organizationRepository->findOneByExternalReference($reference);

        return $organization ?? throw new AccountingException(sprintf('No organization "%s".', $reference));
    }
}
