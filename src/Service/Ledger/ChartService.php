<?php

namespace Wexample\SymfonyAccounting\Service\Ledger;

use Doctrine\ORM\EntityManagerInterface;
use Wexample\SymfonyAccounting\Class\ChartAccountDefinition;
use Wexample\SymfonyAccounting\Entity\Account;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\AccountNature;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\AccountRepository;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;

/**
 * The ledger's chart of accounts: loading datasets, finding accounts by number
 * or by role, creating them on first use.
 */
class ChartService
{
    /** @var array<string, Account> Accounts created in this request, not flushed yet. */
    private array $pending = [];

    /** @var array<string, array<string, ChartAccountDefinition>> Chart definitions by jurisdiction, then number. */
    private array $definitions = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accountRepository,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
    ) {
    }

    /**
     * Loads a dataset of the ledger's jurisdiction. Existing accounts are kept,
     * so a dataset can be loaded again after an update.
     *
     * @return int The number of accounts created.
     */
    public function loadDataset(
        Ledger $ledger,
        ?string $dataset = null
    ): int {
        $jurisdiction = $this->jurisdictionRegistry->forLedger($ledger);
        $dataset ??= $jurisdiction->getDefaultChartDataset();

        if (null === $dataset) {
            return 0;
        }

        $existing = $this->accountRepository->findIndexedByNumber($ledger);
        $created = 0;

        foreach ($jurisdiction->getChartAccounts($dataset) as $definition) {
            if (! isset($existing[$definition->number])) {
                $existing[$definition->number] = $this->createFromDefinition($ledger, $definition, $dataset);
                ++$created;
            }
        }

        $this->entityManager->flush();

        return $created;
    }

    public function findAccount(
        Ledger $ledger,
        string $number
    ): ?Account {
        return $this->pending[$this->key($ledger, $number)]
            ?? $this->accountRepository->findOneByNumber($ledger, $number);
    }

    /**
     * The account with this number, created when missing: labelled from the
     * jurisdiction's chart when it knows the number, or from its closest parent.
     */
    public function getAccount(
        Ledger $ledger,
        string $number
    ): Account {
        if (! preg_match('/^\d+[0-9A-Z]*$/', $number)) {
            throw new AccountingException(sprintf('"%s" is not an account number.', $number));
        }

        $account = $this->findAccount($ledger, $number);

        if ($account) {
            return $account;
        }

        $definition = $this->findDefinition($ledger, $number);
        $account = $this->createFromDefinition(
            $ledger,
            $definition ?? new ChartAccountDefinition($number, $this->buildLabel($ledger, $number)),
            $definition ? ($this->jurisdictionRegistry->forLedger($ledger)->getDefaultChartDataset() ?? 'custom') : 'custom'
        );

        if (! $definition) {
            $parent = $this->findParent($ledger, $number);
            $account
                ->setNature($parent?->getNature() ?? AccountNature::Both)
                ->setLettrable($parent?->isLettrable() ?? false);
        }

        return $account;
    }

    public function getRoleNumber(
        Ledger $ledger,
        AccountRole $role
    ): string {
        $number = $ledger->getAccountRoles()[$role->value]
            ?? $this->jurisdictionRegistry->forLedger($ledger)->getAccountNumber($role);

        if (null === $number) {
            throw new AccountingException(sprintf('No account is set for the role "%s".', $role->value));
        }

        return $number;
    }

    public function getRoleAccount(
        Ledger $ledger,
        AccountRole $role
    ): Account {
        $account = $this->getAccount($ledger, $this->getRoleNumber($ledger, $role));

        if (in_array($role, [AccountRole::Customers, AccountRole::Suppliers, AccountRole::DoubtfulCustomers], true)
            && ! $account->isLettrable()) {
            $account->setLettrable(true);
        }

        return $account;
    }

    private function findDefinition(
        Ledger $ledger,
        string $number
    ): ?ChartAccountDefinition {
        $jurisdiction = $this->jurisdictionRegistry->forLedger($ledger);
        $key = $jurisdiction->getCountryCode() ?? '';

        if (! isset($this->definitions[$key])) {
            $this->definitions[$key] = [];

            foreach ($jurisdiction->getChartDatasets() as $dataset) {
                foreach ($jurisdiction->getChartAccounts($dataset) as $definition) {
                    $this->definitions[$key][$definition->number] ??= $definition;
                }
            }
        }

        return $this->definitions[$key][$number] ?? null;
    }

    private function findParent(
        Ledger $ledger,
        string $number
    ): ?Account {
        for ($length = strlen($number) - 1; $length > 0; --$length) {
            $parent = $this->findAccount($ledger, substr($number, 0, $length));

            if ($parent) {
                return $parent;
            }
        }

        return null;
    }

    private function buildLabel(
        Ledger $ledger,
        string $number
    ): string {
        for ($length = strlen($number) - 1; $length > 0; --$length) {
            $prefix = substr($number, 0, $length);
            $parent = $this->findAccount($ledger, $prefix) ?? $this->findDefinition($ledger, $prefix);

            if ($parent) {
                return $parent instanceof Account ? $parent->getLabel() : $parent->label;
            }
        }

        return 'Account '.$number;
    }

    private function createFromDefinition(
        Ledger $ledger,
        ChartAccountDefinition $definition,
        string $source
    ): Account {
        $account = (new Account())
            ->setLedger($ledger)
            ->setNumber($definition->number)
            ->setLabel($definition->label)
            ->setNature($definition->nature)
            ->setLettrable($definition->lettrable)
            ->setCounterpartNumber($definition->counterpartNumber)
            ->setSource($source);

        $this->entityManager->persist($account);
        $this->pending[$this->key($ledger, $definition->number)] = $account;

        return $account;
    }

    private function key(
        Ledger $ledger,
        string $number
    ): string {
        return $ledger->getId().'|'.$number;
    }
}
