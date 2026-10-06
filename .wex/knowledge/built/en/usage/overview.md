## A ledger

```php
$ledger = $ledgerService->create('Acme SRL', 'BE', fiscalYearStart: new DateTimeImmutable('2026-01-01'));
$ledger->setLegalIdentifier('0123.456.749')->setVatNumber('BE0123456749')->setInvoicePrefix('ACM');
$customer = $partyService->create($ledger, 'Client SA');           // gets its auxiliary code, CCLIENTSA
```

`create()` loads the jurisdiction's chart and journals. Accounts missing from the chart are created on first use, labelled after their closest parent. The ledger's settings (`setSettings()`) tune the rest: `default_vat_rate`, `invoice_number_pattern`, `hours_per_day`, `late_penalty_rate`, `auto_post_invoices`… — each documented where it is read.

## Documents

```php
$invoice = $invoiceFactory->create($ledger, party: $customer);
$invoiceFactory->addItem($invoice, 'Development', 50000, '2j');   // 2 days at 500.00
$emissionService->emit($invoice);                                  // numbered, frozen, booked
```

Emission is the only way a document becomes official: VAT resolved per item (domestic, intra-EU, export, reverse charge, franchise), gap-free number for sales, issuer and party identities (name, identifiers, bank) frozen into snapshots — addresses are read from the entities, their country being a relation, `InvoiceEmittedEvent`, then the entry in the ledger. Quotations convert to bills (`createBillFromQuotation`, deducting deposit bills), bills get credit notes and penalties (`LatePenaltyCalculator`), models renew monthly.

`UblInvoiceBuilder` writes the structured electronic invoice (UBL 2.1, Peppol BIS Billing 3.0 / EN 16931): mandatory for B2B invoices in Belgium since 2026, one of the formats of the French reform. `UblInvoiceReader` does the reverse with received ones: a purchase draft, supplier found by VAT number or created with its IBAN, checked against the supplier's stated total. Sending and receiving through a Peppol access point is left to a remote package. Validate the output on the Peppol testbed before going live.

`InvoiceDocumentDataBuilder` gives everything a printed document shows; `PdfFactoryDocumentBuilder` turns it into a pdf-factory document, and `PdfFactoryRenderer` posts it.

## Bank

```php
$bankImportService->importContent($bankAccount, $fileContent);     // CAMT, OFX, CODA, LBP…: detected
$matchingService->run($ledger);                                    // proposals and sure matches
$allocationService->allocate($line, $invoice);                     // or allocateToAccount(), linkTransfer()
$letteringService->letterLedger($ledger);
```

Imports never duplicate a line. Matchers propose (pending) or settle (validated) by provider payment, payment reference, label rules, transfers between the ledger's accounts and exact amounts; refusing a proposal excludes the pair for good. Validated allocations are booked at once, one entry per payment.

A bank account whose `provider` is set (`stripe`) is fed by `ProviderBalanceImporter` through symfony-remote-payment: gross payments, fees (booked on the bank fees account), refunds, payouts (matched as transfers).

## Period end

```php
$vatReturn = $vatReturnService->compute($ledger, $from, $to);
$vatReturnService->fillForms($vatReturn);   // CA3, CA12, BE periodic… from the jurisdiction packages
$vatReturnService->settle($vatReturn);
$closingService->close($fiscalYear);         // checks, result, opening entries of the next year, lock
```

Before closing, `DepreciationService` books the year's depreciation of the fixed assets (straight-line, pro rata temporis by day; `accounting:depreciation`), `AccrualService` posts adjustments that reverse themselves on the next year's first day, and the checks warn about anything missing.

Reports are computed on demand: `TrialBalanceService`, `GeneralLedgerService`, `FinancialStatementService` (balance sheet, income statement from the jurisdiction's layouts), `AgedBalanceService`. `LedgerExportService` writes the books (FEC, CSV), `EntryImportService` takes another bookkeeping over.

## Collections and supplier payments

`DunningService` finds the reminders due by level (`dunning_levels`, days late; `accounting:dunning --record`) and keeps their history on the document; the host sends them on `InvoiceReminderRecordedEvent`. `SepaCreditTransferBuilder` writes the pain.001 file paying a batch of purchases, structured references included.

## Checks

`InvoiceChecker`, `BankTransactionChecker` and `FiscalYearChecker` report through symfony-check. Errors of the fiscal year block its closing.
