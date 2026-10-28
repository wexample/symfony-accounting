# symfony_accounting

Version: 9.0.1

Accounting for Symfony: ledgers, double-entry bookkeeping, invoices, bank reconciliation, VAT, closing and reports, with country rules in jurisdiction packages

## Table of Contents

- [Installation](#installation)
- [Introduction](#introduction)

## Installation

```php
// config/bundles.php
Wexample\SymfonyCheck\WexampleSymfonyCheckBundle::class => ['all' => true],
Wexample\SymfonyRemotePayment\WexampleSymfonyRemotePaymentBundle::class => ['all' => true],
Wexample\SymfonyPayment\WexampleSymfonyPaymentBundle::class => ['all' => true],
Wexample\SymfonyMoney\WexampleSymfonyMoneyBundle::class => ['all' => true],
Wexample\SymfonyRemotePdfFactory\WexampleSymfonyRemotePdfFactoryBundle::class => ['all' => true],
Wexample\SymfonyRemoteDocumentReader\WexampleSymfonyRemoteDocumentReaderBundle::class => ['all' => true],
Wexample\SymfonyRemoteVies\WexampleSymfonyRemoteViesBundle::class => ['all' => true],
Wexample\SymfonyMedia\WexampleSymfonyMediaBundle::class => ['all' => true],
Wexample\SymfonyRemotePeppol\WexampleSymfonyRemotePeppolBundle::class => ['all' => true],
Wexample\SymfonyAccounting\WexampleSymfonyAccountingBundle::class => ['all' => true],
// One per country the app keeps books in:
Wexample\SymfonyAccountingFr\WexampleSymfonyAccountingFrBundle::class => ['all' => true],
Wexample\SymfonyAccountingBe\WexampleSymfonyAccountingBeBundle::class => ['all' => true],
```

The API needs `symfony-api` (and its `symfony-security`, `symfony-translations`, `symfony-loader` bundles) and its routes imported:

```yaml
# config/routes/wexample_symfony_accounting.yaml
wexample_symfony_accounting:
  resource: '@WexampleSymfonyAccountingBundle/Resources/config/routes.yaml'
```

The entities are mapped automatically (`accounting_*` tables); generate the migration in the app.

```yaml
# config/packages/wexample_symfony_accounting.yaml
wexample_symfony_accounting:
  document:
    default_stylesheet: true                  # start from the sober charter shipped here
    stylesheets: ['%kernel.project_dir%/assets/pdf/invoice.css']   # the application's, posted after it
```

The service itself is configured by `wexample_symfony_remote_pdf_factory.url`, and reading received Factur-X by `wexample_symfony_remote_document_reader.url`.

Crons worth running:

```bash
bin/console accounting:provider-import --match   # Stripe balances, then matching
bin/console accounting:invoice-renew --emit      # monthly documents from models
bin/console check:run invoices                   # overdue, overpaid, missing files…
```

`symfony-accounting` keeps the books of one or many companies in a Symfony app: double-entry ledger, invoicing, bank reconciliation, VAT, closing and financial statements. An accounting firm holds one ledger per client; an app keeping its own books holds one.

Nothing in it is specific to a country. Charts of accounts, VAT rules, legal mentions and statement layouts come from jurisdiction packages — `symfony-accounting-fr` and `symfony-accounting-be` — and a default jurisdiction serves the countries no package covers.

It never renders anything: printed documents are data handed to `pdf-factory`, and screens live in `symfony-accounting-ds` (to come).
