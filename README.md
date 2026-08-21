# symfony_accounting

Version: 1.0.95

`wexample/symfony-accounting` is a Symfony bundle that provides the data layer for financial applications: abstract Doctrine entities for bank organisations and transactions (typed as `statement` or `transaction`), a repository that constructs them, and a deduplication-aware service that persists a record only when `saveTransactionIfNotExists` confirms it is new. It ships concrete bank-export parsers for La Banque Postale CSV (2019 and 2023 formats), Crédit Agricole XLS (2023), and Stripe CSV (2021), together with French bank-info traits (IBAN, BIC, RIB) ready to mix into any entity or form. It is aimed at Symfony developers building financial back-offices who need to ingest real bank exports and reconcile transactions with invoices without writing parsing and deduplication boilerplate from scratch.

## Table of Contents

- [Architecture](#architecture)
- [Integration in the Suite](#integration-in-the-suite)
- [Dependencies](#dependencies)
- [Versioning & Compatibility Policy](#versioning--compatibility-policy)
- [License](#license)
- [About us](#about-us)
- [Migration Notes](#migration-notes)

## Architecture

The bundle is a pure library — no controllers, no routes. It ships abstract base classes that the host application extends, a set of concrete bank-export parsers, and a small value object. Symfony's service container wires the parsers automatically through src/Resources/config/services.yaml.

### Bundle bootstrap

src/WexampleSymfonyAccountingBundle.php extends `AbstractBundle` from `wexample/symfony-helpers`. It adds nothing beyond the class declaration; the loader contract is satisfied by src/DependencyInjection/WexampleSymfonyAccountingExtension.php, whose `load()` calls `$this->loadConfig(__DIR__, $container)` (inherited helper) to register every class under `src/Service/` as an autowired, autoconfigured, private service.

### Entities

Two abstract Doctrine entities ship with the bundle; the host application must extend both.

src/Entity/AbstractAccountingTransactionEntity.php extends `AbstractEntity` (symfony-helpers). It declares the only two string constants the rest of the code uses to stamp records: `TYPE_STATEMENT = 'statement'` and `TYPE_TRANSACTION = 'transaction'`. Fields such as bank, type, date, description, and amount are expected on the concrete class; the repository and parsers call setters by name.

src/Entity/AbstractBankOrganizationEntity.php extends `Organization` (symfony-helpers). Concrete bank entities — one per financial institution the application tracks — extend this class.

### Repository

src/Repository/AbstractAccountingTransactionRepository.php extends `AbstractRepository` (symfony-helpers) and owns the construction of a bare transaction object. Its `createAccountingTransaction()` resolves the concrete class name through the abstract `getEntityType()`, instantiates it, then calls `setBank()`, `setType()`, `setDateCreated()`, `setDescription()`, and `setAmount()`. The returned object is **not** persisted here; the caller decides whether to save it.

### Service layer

#### AbstractAccountingTransactionEntityService

src/Service/Entity/AbstractAccountingTransactionEntityService.php extends `AbstractEntityService` (symfony-helpers) and implements src/Service/Entity/Interface/AccountingTransactionEntityServiceInterface.php (a marker interface). Its one concrete method, `saveTransactionIfNotExists()`, calls the abstract `findSameTransaction()` (provided by the host subclass) and, when no duplicate is found, calls `$this->getEntityRepository()->add($transaction)`. Every parser delegates persistence through this single point.

### AccountingCollection

src/Class/AccountingCollection.php is a plain PHP value object, not a service. It holds two keyed arrays — one for `Invoice` objects and one for `AccountingTransaction` objects — both indexed by entity ID to prevent duplicates. After any insertion it recomputes a `fingerPrint`: sorted, prefixed IDs (`I{id}` / `T{id}`) joined with `-`. `contains()` checks membership by ID comparison. The class is used by the host application to group related invoices and transactions before reconciliation.

### Bank-export parsers

All parsers live under `src/Service/` and form a two-level hierarchy beneath a common abstract base.

#### AbstractBankExportParser

src/Service/AbstractBankExportParser.php is the root. Its constructor injects `EntityManagerInterface` and `AbstractAccountingTransactionEntityService`; it resolves the `AccountingTransaction` repository from the entity manager immediately. It provides:

- `parseFile()` — reads the file path into a string then delegates to `parseContent()`.
- `createCsvFromBody()` — wraps `League\Csv\Reader::createFromString()` with a configurable delimiter.
- `parseDate()` — trims the input, calls `DateTime::createFromFormat()`, then normalises to midnight via `DateHelper::startOfDay()`.
- `saveTransactionOfNotExists()` — calls `$this->accountingTransactionRepo->createAccountingTransaction()` then `$this->accountingTransactionEntityService->saveTransactionIfNotExists()`.
- Abstract `parseContent(AbstractBankOrganizationEntity, $content, array $options): int` — must be implemented by each concrete parser; returns the count of newly persisted records.

#### CsvWithMetadataBankExportParser

src/Service/CsvWithMetadataBankExportParser.php extends `AbstractBankExportParser`. It handles CSV files that carry a multi-row metadata header above the transaction rows. `parseContent()` calls `convertCsvTextToTransaction()`, which:

1. Parses the full file as a `League\Csv\Reader`.
2. Extracts the export date and opening balance via the abstract `getDateExport()` and `getAccountBalanceStatement()`, then saves a `TYPE_STATEMENT` row.
3. Skips `$this->headerHeight` rows (set by subclass), then iterates body records through `convertCsvRecords()`, calling the three abstract accessors `getRecordDescription()`, `getRecordDateString()`, and `getRecordAmountString()` for each row.

Concrete subclasses:

- src/Service/FrLbp2023BankExportParser.php — La Banque Postale 2023 CSV, `headerHeight = 6`, extracts the export date by regex from row 2, balance from row 4 column 1.
- src/Service/FrLbp2019BankExportParser.php — La Banque Postale 2019. Overrides `parseContent()`: if the file extension is `.txt`, it calls `convertPdfTextToTransaction()` instead of the normal CSV path. The `.txt` branch runs `convertPdfTextToCsv()`, a line-by-line state machine that reconstructs `DD/MM/YYYY;"description";amount` CSV from copy-pasted PDF text, handling multi-line descriptions and sign detection for outgoing transfers. For native CSV files it falls through to `parent::parseContent()` with `headerHeight = 8`.

#### XlsBankExportParser

src/Service/XlsBankExportParser.php extends `AbstractBankExportParser`. It overrides `parseFile()` to load the file through `PhpOffice\PhpSpreadsheet\IOFactory::load()` and pass a `Spreadsheet` object to `parseContent()`. `parseContent()` reads the export date from cell `A1` by regex and the opening balance from cell `C7` via `PriceHelper::priceToInt()`, saves a `TYPE_STATEMENT` row if both are present, then iterates worksheet rows from `$this->headerHeight`. Three abstract methods — `getRowDescription()`, `getRowDateString()`, `getRowAmountString()` — each receive a `Row` object.

Concrete subclass:

- src/Service/FrCa2023BankExportParser.php — Crédit Agricole 2023 XLS, `headerHeight = 11`. Date in column A is an Excel serial number decoded via `PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject()`. Amount picks column D (credit) and falls back to `"-" . column C` (debit).

#### Stripe2021BankExportParser

src/Service/Stripe2021BankExportParser.php extends `AbstractBankExportParser` directly. It takes two additional constructor arguments — `InvoiceEntityService` and `InvoiceItemEntityService` from the host application — making it the only parser coupled to the invoice domain. `parseContent()`:

1. Parses a comma-separated CSV with a header row (`setHeaderOffset(0)`).
2. For each record, saves the main amount as a `TYPE_TRANSACTION` via `saveNewTransactionFromStripeCsvRecord()`.
3. If the record carries a non-zero `Fee`, saves a second (negated) transaction for the fee, then finds or creates a monthly `Invoice` grouped by accounting code, assigns the fee transaction to it, rebuilds invoice items, and flushes.

### French bank-info traits

Two paired traits carry the French bank-account fields that any concrete bank-organisation entity and its admin form will need.

src/Entity/Traits/FrBankInfo2018Trait.php adds eight mapped columns to an entity: `bank_owner`, `bank_iban`, `bank_bic`, `bank_location`, `bank_rib_bank`, `bank_rib_agency`, `bank_rib_account`, `bank_rib_key`. It also provides `ribValidate()`, which implements the modulo-97 RIB checksum algorithm.

src/Form/Traits/FrBankInfo2018Trait.php provides `buildFrBankInfo2018(FormBuilderInterface $builder)` which adds the matching Symfony form fields (text inputs and one numeric field for the key) in a single call.

### Call path: file to database

A typical import resolves as follows:

1. The host controller or command picks the correct concrete parser (e.g. `FrLbp2023BankExportParser`) and calls `parseFile($bank, '/path/to/export.csv')`.
2. `AbstractBankExportParser::parseFile()` reads the file and delegates to `parseContent()`.
3. `CsvWithMetadataBankExportParser::parseContent()` reads the metadata header, saves the opening-balance statement row, then iterates body records.
4. For each record, `saveTransactionOfNotExists()` on the base class calls `AbstractAccountingTransactionRepository::createAccountingTransaction()` to build an unsaved entity, then hands it to `AbstractAccountingTransactionEntityService::saveTransactionIfNotExists()`.
5. The entity service calls the host-supplied `findSameTransaction()`. If no duplicate exists it persists the record and returns `true`; the parser increments its counter.
6. `parseFile()` returns the total count of newly saved transactions to the caller.

## Integration in the Suite

This package is part of the Wexample Suite — a collection of high-quality, modular tools designed to work seamlessly together across multiple languages and environments.

### Related Packages

The suite includes packages for configuration management, file handling, prompts, and more. Each package can be used independently or as part of the integrated suite.

Visit the [Wexample Suite documentation](https://docs.wexample.com) for the complete package ecosystem.

## Dependencies

- wexample/symfony-forms: >=1.0.85
- league/csv: ^9.5

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
