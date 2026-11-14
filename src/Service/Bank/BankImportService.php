<?php

namespace Wexample\SymfonyAccounting\Service\Bank;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Wexample\SymfonyAccounting\Class\ImportResult;
use Wexample\SymfonyAccounting\Class\ParsedStatement;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Entity\BankStatement;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Helper\ProviderMovementHelper;
use Wexample\SymfonyAccounting\Repository\BankAccountRepository;
use Wexample\SymfonyAccounting\Repository\BankStatementRepository;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;

/**
 * Stores parsed bank lines without ever duplicating them.
 *
 * A line with an external id is new when no line of the account — of any of
 * its shares, the lines pointed to other ledgers included — has that id.
 * Without one, lines are compared by fingerprint (date, amount, label), counting
 * occurrences: two identical coffees on the same day stay two lines, and the same
 * file imported twice adds nothing.
 */
class BankImportService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BankTransactionRepository $transactionRepository,
        private readonly BankStatementRepository $statementRepository,
        private readonly BankStatementParserRegistry $parserRegistry,
        private readonly AllocationService $allocationService,
        private readonly ChartService $chartService,
        private readonly BankAccountRepository $bankAccountRepository,
    ) {
    }

    /**
     * @param string|null $parserKey Detected from the content when null.
     */
    public function importContent(
        BankAccount $bankAccount,
        string $content,
        ?string $parserKey = null,
        array $options = [],
        ?string $filename = null,
    ): ImportResult {
        $parser = null === $parserKey
            ? $this->parserRegistry->detect($content, $filename)
            : $this->parserRegistry->get($parserKey);

        return $this->import($bankAccount, $parser->parse($content, $options));
    }

    public function import(
        BankAccount $bankAccount,
        ParsedStatement $statement
    ): ImportResult {
        if ($statement->iban && $bankAccount->getIban()
            && strtoupper(str_replace(' ', '', $statement->iban)) !== $bankAccount->getIban()) {
            throw new AccountingException(sprintf('This file is about account %s, not %s.', $statement->iban, $bankAccount->getIban()));
        }

        $result = new ImportResult(Uuid::v7()->toRfc4122());
        $seenFingerprints = [];
        $shares = $this->bankAccountRepository->findShares($bankAccount);
        [$knownIds, $knownFingerprints] = $this->findKnown($shares, $statement);

        foreach ($statement->transactions as $parsed) {
            if (0 === $parsed->amount) {
                continue;
            }

            if ($parsed->externalId) {
                if ($this->isKnownId($shares, $parsed->externalId, $knownIds)
                    || isset($seenFingerprints['id:'.$parsed->externalId])) {
                    ++$result->duplicates;

                    continue;
                }

                $seenFingerprints['id:'.$parsed->externalId] = 1;
            } else {
                $fingerprint = BankTransaction::buildFingerprint($parsed->date, $parsed->amount, $parsed->label);
                $occurrence = $seenFingerprints[$fingerprint] = ($seenFingerprints[$fingerprint] ?? 0) + 1;
                if (($knownFingerprints[$fingerprint] ?? 0) >= $occurrence) {
                    ++$result->duplicates;

                    continue;
                }
            }

            $transaction = (new BankTransaction())
                ->setBankAccount($bankAccount)
                ->setDate($parsed->date)
                ->setValueDate($parsed->valueDate)
                ->setLabel($parsed->label)
                ->setAmount($parsed->amount)
                ->setExternalId($parsed->externalId)
                ->setCounterpartyName($parsed->counterpartyName)
                ->setCounterpartyIban($parsed->counterpartyIban)
                ->setReference($parsed->reference)
                ->setPaymentReference($parsed->paymentReference)
                ->setMetadata($parsed->metadata)
                ->setImportBatch($result->batch)
                ->refreshFingerprint();

            $this->entityManager->persist($transaction);
            $result->created[] = $transaction;
        }

        foreach ($statement->balances as $balance) {
            if (! $this->statementRepository->findOneByDate($bankAccount, $balance->date)) {
                $this->entityManager->persist(
                    (new BankStatement())
                        ->setBankAccount($bankAccount)
                        ->setDate($balance->date)
                        ->setBalance($balance->balance)
                );
                ++$result->balances;
            }
        }

        $bankAccount->setDateLastImport(new DateTimeImmutable());
        $this->entityManager->flush();
        $this->allocateProviderFees($result);

        return $result;
    }

    /**
     * What the books already hold of a statement, read in two queries rather
     * than one per line: the bank's ids found, and per fingerprint the lines
     * without an id that carry it.
     *
     * @param list<BankAccount> $shares
     * @return array{array<string, true>, array<string, int>}
     */
    private function findKnown(
        array $shares,
        ParsedStatement $statement
    ): array {
        $ids = [];
        $fingerprints = [];

        foreach ($statement->transactions as $parsed) {
            if (0 === $parsed->amount) {
                continue;
            }

            if ($parsed->externalId) {
                $ids[$parsed->externalId] = true;
            } else {
                $fingerprints[BankTransaction::buildFingerprint($parsed->date, $parsed->amount, $parsed->label)] = true;
            }
        }

        $knownIds = [];
        if ([] !== $ids) {
            foreach ($this->transactionRepository->findBy(['bankAccount' => $shares, 'externalId' => array_map('strval', array_keys($ids))]) as $transaction) {
                $knownIds[(string) $transaction->getExternalId()] = true;
            }
        }

        $knownFingerprints = [];
        if ([] !== $fingerprints) {
            foreach ($this->transactionRepository->findBy(['bankAccount' => $shares, 'fingerprint' => array_map('strval', array_keys($fingerprints))]) as $transaction) {
                if (null === $transaction->getExternalId()) {
                    $knownFingerprints[$transaction->getFingerprint()] = ($knownFingerprints[$transaction->getFingerprint()] ?? 0) + 1;
                }
            }
        }

        return [$knownIds, $knownFingerprints];
    }

    /**
     * Whether the bank's id is in the books. The database compares ids by its
     * own collation (case and accents aside on MySQL): an id found only that
     * way is asked again, alone, as the books asked it before.
     *
     * @param list<BankAccount> $shares
     * @param array<string, true> $knownIds
     */
    private function isKnownId(
        array $shares,
        string $externalId,
        array $knownIds
    ): bool {
        if (isset($knownIds[$externalId])) {
            return true;
        }

        foreach (array_keys($knownIds) as $known) {
            if (0 === strcasecmp((string) $known, $externalId)) {
                return null !== $this->transactionRepository->findOneByExternalId($shares, $externalId);
            }
        }

        return false;
    }

    /**
     * Fees a provider kept are known for sure: they go straight to the bank fees account.
     */
    private function allocateProviderFees(ImportResult $result): void
    {
        foreach ($result->created as $transaction) {
            if ($transaction->getMetadata()[ProviderMovementHelper::METADATA_FEE] ?? false) {
                $this->allocationService->allocateToAccount(
                    $transaction,
                    $this->chartService->getRoleNumber($transaction->getLedger(), AccountRole::BankFees),
                    label: $transaction->getLabel(),
                    status: AllocationStatus::Validated,
                    origin: 'provider_fee',
                );
            }
        }
    }
}
