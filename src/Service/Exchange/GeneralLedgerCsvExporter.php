<?php

namespace Wexample\SymfonyAccounting\Service\Exchange;

use Wexample\SymfonyAccounting\Class\ExportFile;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Interface\LedgerExporterInterface;
use Wexample\SymfonyAccounting\Service\Report\GeneralLedgerService;
use Wexample\SymfonyMoney\Helper\MoneyHelper;

/**
 * The general ledger as accountants read it: account by account (party accounts
 * split per auxiliary account), lines with their running balance and letters.
 */
class GeneralLedgerCsvExporter implements LedgerExporterInterface
{
    public function __construct(
        private readonly GeneralLedgerService $generalLedgerService,
    ) {
    }

    public function getKey(): string
    {
        return 'csv_general_ledger';
    }

    public function getLabel(): string
    {
        return 'General ledger (CSV)';
    }

    public function supports(FiscalYear $fiscalYear): bool
    {
        return true;
    }

    public function export(
        FiscalYear $fiscalYear,
        array $options = []
    ): ExportFile {
        $delimiter = $options['delimiter'] ?? ';';
        $currency = $fiscalYear->getLedger()->getCurrencyCode();
        $money = fn (int $amount) => MoneyHelper::toDecimal($amount, $currency);
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['account', 'account_label', 'auxiliary', 'date', 'journal', 'number', 'piece', 'label', 'debit', 'credit', 'balance', 'letter'], $delimiter, '"', '');

        foreach ($this->generalLedgerService->build($fiscalYear->getLedger(), $fiscalYear, byAuxiliary: true) as $account) {
            foreach ($account['lines'] as ['line' => $line, 'balance' => $balance]) {
                $entry = $line->getEntry();
                fputcsv($handle, [
                    $account['number'],
                    $account['label'],
                    $account['auxiliary'],
                    $entry->getDate()->format('Y-m-d'),
                    $entry->getJournal()->getCode(),
                    $entry->getNumber(),
                    $entry->getPieceReference(),
                    $line->getLabel(),
                    $money($line->getDebit()),
                    $money($line->getCredit()),
                    $money($balance),
                    $line->getLetter(),
                ], $delimiter, '"', '');
            }
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return new ExportFile('general-ledger-'.$fiscalYear->getLabel().'.csv', $content);
    }
}
