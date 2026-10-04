<?php

namespace Wexample\SymfonyAccounting\Service\Bank\Parser;

use Wexample\SymfonyAccounting\Class\ParsedStatement;
use Wexample\SymfonyAccounting\Class\ParsedTransaction;
use Wexample\SymfonyAccounting\Helper\ProviderMovementHelper;

/**
 * Stripe's balance history export (Balance → Export). Each row gives its gross
 * amount, plus a separate line for the fee Stripe kept, with the same external
 * ids as the API import (ProviderBalanceImporter): importing both never duplicates.
 */
class StripeCsvBankStatementParser extends AbstractBankStatementParser
{
    public function getKey(): string
    {
        return 'stripe_csv';
    }

    public function getLabel(): string
    {
        return 'Stripe balance export (CSV)';
    }

    public function supports(
        string $content,
        ?string $filename = null
    ): bool {
        $firstLine = strtolower(strtok($this->removeBom($content), "\n") ?: '');

        return str_contains($firstLine, 'id,') && str_contains($firstLine, 'fee') && str_contains($firstLine, 'net');
    }

    public function parse(
        string $content,
        array $options = []
    ): ParsedStatement {
        $csv = $this->createCsv($content, ',');
        $csv->setHeaderOffset(0);
        $statement = new ParsedStatement();

        foreach ($csv->getRecords() as $record) {
            $record = array_change_key_case(array_map('trim', $record));
            $currency = strtoupper($record['currency'] ?? 'EUR');
            $created = $record['created (utc)'] ?? $record['created'] ?? '';
            $date = $this->parseDate(substr($created, 0, 16), 'Y-m-d H:i');
            $id = $record['id'];
            $type = strtolower($record['type'] ?? $record['reporting_category'] ?? '');
            $description = $record['description'] ?? '' ?: ucfirst($type);
            $amount = $this->parseAmount($record['amount'], $currency);
            $fee = $this->parseAmount($record['fee'] ?? '0', $currency);
            $source = $record['source'] ?? null ?: null;

            $statement->addTransaction(new ParsedTransaction(
                date: $date,
                amount: $amount,
                label: $this->cleanLabel($description),
                externalId: ProviderMovementHelper::grossId($id),
                reference: $source,
                metadata: ['provider_type' => $type],
            ));

            if (0 !== $fee) {
                $statement->addTransaction(new ParsedTransaction(
                    date: $date,
                    amount: -$fee,
                    label: $this->cleanLabel('Fee '.$description),
                    externalId: ProviderMovementHelper::feeId($id),
                    reference: $source,
                    metadata: ['provider_type' => 'fee', ProviderMovementHelper::METADATA_FEE => true],
                ));
            }
        }

        return $statement;
    }
}
