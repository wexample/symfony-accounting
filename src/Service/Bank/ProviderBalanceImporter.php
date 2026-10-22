<?php

namespace Wexample\SymfonyAccounting\Service\Bank;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\ImportResult;
use Wexample\SymfonyAccounting\Class\ParsedStatement;
use Wexample\SymfonyAccounting\Class\ParsedTransaction;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Helper\ProviderMovementHelper;
use Wexample\SymfonyRemotePayment\Class\BalanceMovement;
use Wexample\SymfonyRemotePayment\Enum\BalanceMovementType;
use Wexample\SymfonyRemotePayment\Service\PaymentProviderRegistry;

/**
 * Imports the balance a payment provider keeps (Stripe…) as the lines of a bank
 * account whose `provider` names it: gross payments, the fees kept (allocated at
 * once to the bank fees account by BankImportService), refunds, and payouts, which the matching then
 * links as transfers to the real bank account.
 *
 * External ids are the provider's movement ids, shared with the CSV parser, so
 * the API and a CSV export never duplicate each other.
 */
class ProviderBalanceImporter
{
    public function __construct(
        private readonly PaymentProviderRegistry $providerRegistry,
        private readonly BankImportService $importService,
    ) {
    }

    /**
     * @param DateTimeImmutable|null $from Defaults to two days before the last import, or 90 days ago.
     */
    public function import(
        BankAccount $bankAccount,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null
    ): ImportResult {
        $provider = $bankAccount->getProvider();

        if (! $provider) {
            throw new AccountingException('This account is not linked to a payment provider.');
        }

        $from ??= $bankAccount->getDateLastImport()?->modify('-2 days') ?? new DateTimeImmutable('-90 days');
        $statement = new ParsedStatement(currencyCode: $bankAccount->getCurrencyCode());

        foreach ($this->providerRegistry->getBalanceReader($provider)->readBalance($from, $to) as $movement) {
            foreach ($this->toLines($movement) as $line) {
                $statement->addTransaction($line);
            }
        }

        return $this->importService->import($bankAccount, $statement);
    }

    /**
     * @return list<ParsedTransaction>
     */
    public function toLines(BalanceMovement $movement): array
    {
        $date = $movement->dateCreated->setTime(0, 0);
        $description = $movement->description ?: ucfirst($movement->type->value);
        $type = $movement->type;
        $lines = [];

        if (BalanceMovementType::Fee === $type) {
            return [new ParsedTransaction(
                date: $date,
                amount: $movement->amount - $movement->fee,
                label: $description,
                externalId: ProviderMovementHelper::grossId($movement->externalId),
                reference: $movement->sourceReference,
                metadata: ['provider_type' => $type->value, ProviderMovementHelper::METADATA_FEE => true],
            )];
        }

        $lines[] = new ParsedTransaction(
            date: $date,
            amount: $movement->amount,
            label: $description,
            externalId: ProviderMovementHelper::grossId($movement->externalId),
            reference: $movement->sourceReference,
            paymentReference: $movement->paymentReference,
            metadata: ['provider_type' => $type->value],
        );

        if (0 !== $movement->fee) {
            $lines[] = new ParsedTransaction(
                date: $date,
                amount: -$movement->fee,
                label: 'Fee '.$description,
                externalId: ProviderMovementHelper::feeId($movement->externalId),
                reference: $movement->sourceReference,
                paymentReference: $movement->paymentReference,
                metadata: ['provider_type' => 'fee', ProviderMovementHelper::METADATA_FEE => true],
            );
        }

        return $lines;
    }
}
