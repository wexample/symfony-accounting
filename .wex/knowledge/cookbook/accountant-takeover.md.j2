## Taking over a client's books

An accounting firm receives the FEC of a new client and keeps the books from there.

```php
$ledger = $ledgerService->create('Client SARL', 'FR', loadChart: false);
$result = $entryImportService->importContent($ledger, file_get_contents('123456789FEC20251231.txt'), options: [
    'create_fiscal_years' => true,
    'fiscal_year_start_month' => 1,
]);
```

Accounts, journals and auxiliary accounts (as parties, with their codes) are created from the file; letters and validation dates are kept. An unbalanced entry is reported in `$result->errors` and skipped, the others are booked. Then close the imported year (`FiscalYearClosingService::close()`), and the new one opens with its balances.

The same works from a CSV of entries (`csv_entries`), or from any format a package adds as an `EntryImporterInterface`.
