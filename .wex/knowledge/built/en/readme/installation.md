## Installation

```php
// config/bundles.php
Wexample\SymfonyCheck\WexampleSymfonyCheckBundle::class => ['all' => true],
Wexample\SymfonyRemotePayment\WexampleSymfonyRemotePaymentBundle::class => ['all' => true],
Wexample\SymfonyPayment\WexampleSymfonyPaymentBundle::class => ['all' => true],
Wexample\SymfonyMoney\WexampleSymfonyMoneyBundle::class => ['all' => true],
Wexample\SymfonyAccounting\WexampleSymfonyAccountingBundle::class => ['all' => true],
// One per country the app keeps books in:
Wexample\SymfonyAccountingFr\WexampleSymfonyAccountingFrBundle::class => ['all' => true],
Wexample\SymfonyAccountingBe\WexampleSymfonyAccountingBeBundle::class => ['all' => true],
```

The entities are mapped automatically (`accounting_*` tables); generate the migration in the app.

```yaml
# config/packages/wexample_symfony_accounting.yaml
wexample_symfony_accounting:
  pdf_factory:
    url: '%env(default::PDF_FACTORY_URL)%'   # e.g. http://pdf_factory:8000
    stylesheet: null                          # the charter CSS posted with every document
```

Crons worth running:

```bash
bin/console accounting:provider-import --match   # Stripe balances, then matching
bin/console accounting:invoice-renew --emit      # monthly documents from models
bin/console check:run invoices                   # overdue, overpaid, missing files…
```
