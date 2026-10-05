# symfony_accounting

Version: 4.0.0

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

Emission is the only way a document becomes official: VAT resolved per item (domestic, intra-EU, export, reverse charge, franchise), gap-free number for sales, issuer and party frozen into snapshots, `InvoiceEmittedEvent`, then the entry in the ledger. Quotations convert to bills (`createBillFromQuotation`, deducting deposit bills), bills get credit notes and penalties (`LatePenaltyCalculator`), models renew monthly.

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

## Table of Contents

- [A ledger](#a-ledger)
- [Documents](#documents)
- [Bank](#bank)
- [Period end](#period-end)
- [Collections and supplier payments](#collections-and-supplier-payments)
- [Checks](#checks)
- [Architecture](#architecture)
- [Integration in the Suite](#integration-in-the-suite)
- [Dependencies](#dependencies)
- [Versioning & Compatibility Policy](#versioning--compatibility-policy)
- [License](#license)
- [About us](#about-us)
- [Migration Notes](#migration-notes)

## Architecture

### Ledger

src/Entity/Ledger.php is a set of books; every other entity belongs to one. src/Entity/JournalEntry.php and src/Entity/EntryLine.php are the double entry: amounts are int minor units in debit/credit columns, a line may carry a party (its auxiliary account), a letter, and a VAT code with its role (base or tax).

src/Service/Ledger/PostingService.php is the only way into the books: it checks balance and fiscal year, numbers entries without gaps per fiscal year (fiscal year row locked), and reverses rather than deletes. Callers describe entries with src/Class/EntryDraft.php, naming accounts by number or by src/Enum/AccountRole.php; src/Service/Ledger/ChartService.php resolves roles through the ledger's overrides, then its jurisdiction.

Entries know their source (`sourceType`, `sourceId`): an invoice, an allocation, a transfer, an opening. That is what makes booking idempotent and what lettering follows.

### Jurisdictions

src/Interface/JurisdictionInterface.php holds everything a country decides. src/Service/Jurisdiction/AbstractJurisdiction.php implements the EU VAT rules and generates VAT codes (`S_DOM_2100`, `P_RC_2000`, `S_IEUS_0`…); src/Service/Jurisdiction/DefaultJurisdiction.php serves countries without a package. Jurisdiction packages ship data (charts as CSV, statement layouts as PHP arrays) and the mentions' texts as translations.

### Invoicing

src/Entity/Invoice.php uses the symfony-money priced traits: a parent summing its items, VAT per item, discount spread over the VAT bases. Status changes go through src/Service/Invoice/InvoiceWorkflow.php; emission through src/Service/Invoice/InvoiceEmissionService.php. src/Service/Invoice/InvoiceAccountingService.php turns a document into an entry: bases split per account and VAT code so that they sum exactly to each rate's base, self-assessed VAT booked twice, VAT on payments parked on pending accounts.

### Bank

Parsers (src/Interface/BankStatementParserInterface.php) are pure: content in, src/Class/ParsedStatement.php out. src/Service/Bank/BankImportService.php deduplicates by external id, else by fingerprint counted per occurrence. src/Entity/Allocation.php links a line to a document or an account; src/EventSubscriber/BankAccountingSubscriber.php books it and moves the document's payment status. Matchers (src/Interface/TransactionMatcherInterface.php) only propose; src/Service/Bank/MatchingService.php applies proposals above a threshold and never one that was excluded.

src/Service/Ledger/LetteringService.php letters party accounts by connected components: lines of a document, its write-off and its allocations are connected, and payments covering several documents connect them; a balanced component gets a letter.

### Period end and reports

src/Service/Vat/VatReturnService.php sums tagged lines per VAT code — tax only where it is due or deductible, so VAT on debits and on payments need no separate code — and national forms (src/Interface/VatReturnFormInterface.php) place the totals in their boxes. src/Service/Ledger/FiscalYearClosingService.php computes the result on net balances and posts the next year's opening entries, party by party. src/Service/Report/FinancialStatementService.php evaluates the jurisdiction's layouts: an account goes to the first line whose rules take it.

### What is not here yet

- An HTTP API: its shape depends on the screens of `symfony-accounting-ds`, and access to ledgers (firm staff, clients) is a host decision to make first.
- Documents in another currency than their ledger's: refused at emission.
- Partly deductible VAT (cars…), domestic reverse charge (construction), OSS distance sales.

## Integration in the Suite

This package is part of the Wexample Suite — a collection of high-quality, modular tools designed to work seamlessly together across multiple languages and environments.

### Related Packages

The suite includes packages for configuration management, file handling, prompts, and more. Each package can be used independently or as part of the integrated suite.

Visit the [Wexample Suite documentation](https://docs.wexample.com) for the complete package ecosystem.

## Dependencies

- php: >=8.5
- league/csv: ^9.5
- wexample/php-date: >=2.0.0
- wexample/symfony-helpers: >=14.0.0
- wexample/symfony-money: >=5.0.0
- wexample/symfony-geo: >=4.0.0
- wexample/symfony-check: >=2.0.0
- wexample/symfony-payment: >=2.0.0
- wexample/symfony-remote-payment: >=2.0.0

## Versioning & Compatibility Policy

Wexample packages follow **Semantic Versioning** (SemVer):

- **MAJOR**: Breaking changes
- **MINOR**: New features, backward compatible
- **PATCH**: Bug fixes, backward compatible

We maintain backward compatibility within major versions and provide clear migration guides for breaking changes.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

Free to use in both personal and commercial projects.

## About us

[Wexample](https://wexample.com) stands as a cornerstone of the digital ecosystem — a collective of seasoned engineers, researchers, and creators driven by a relentless pursuit of technological excellence. More than a media platform, it has grown into a vibrant community where innovation meets craftsmanship, and where every line of code reflects a commitment to clarity, durability, and shared intelligence.

This packages suite embodies this spirit. Trusted by professionals and enthusiasts alike, it delivers a consistent, high-quality foundation for modern development — open, elegant, and battle-tested. Its reputation is built on years of collaboration, refinement, and rigorous attention to detail, making it a natural choice for those who demand both robustness and beauty in their tools.

Wexample cultivates a culture of mastery. Each package, each contribution carries the mark of a community that values precision, ethics, and innovation — a community proud to shape the future of digital craftsmanship.

## Migration Notes

When upgrading between major versions, refer to the migration guides in the documentation.

Breaking changes are clearly documented with upgrade paths and examples.
