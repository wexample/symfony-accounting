# Extract accounting from network: roadmap and package repair

Opened: 2026-09-24
Updated: 2026-09-24
Author: agent:archeology

## Read this first — status of this todo

> **This is a proposal for discussion, not an order to code.** It was written by the 2026-09 network archaeology pass. Read it, then discuss it with the owner: every design choice and recommendation below is to be challenged and validated **before** any code is written. Do not start implementing on your own.
>
> - Context: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/index.md.j2` (entry point, order between packages), then `sources.md.j2` (where the legacy code lives: archive repo, branch checkouts, GitLab issues) and the domain page linked below.
> - Pending owner decisions affecting this work are listed in `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/recap.md.j2`, section "Décisions qui t'attendent". Where this todo assumes an answer, treat it as an open question.
> - Safety: `NETWORK/local/network` runs on **production data** (real bookkeeping, real invoices in `var/`, a prod dump in `.wex/mysql/dumps/`) — read its code only, never run anything against it. Anonymize any fixture taken from network (bank exports, FEC, mails contain real names/accounts). Never copy secrets found in its history (Stripe keys, tokens, passwords, private keys).

## Goal

network's accounting (in production since 2018) becomes a reusable package family. The work is split across several todos in this folder. Do them in order:

1. **This file**:
   - fix the package so it runs without network;
   - set the shared conventions;
   - add a test kernel.
2. `extract-from-network-bank.md`: transactions, allocations to documents, transfers, matching, statements and reconciliation, lettering, Stripe ledger import.
3. `extract-from-network-invoices.md`: invoices, items, relations, numbering, lifecycle, jurisdiction profiles, item editor front-end.
4. `extract-from-network-ledger.md`: chart of accounts, entries, journals, fiscal year, closing, balance, balance sheet, income statement, general ledger.

Localized rules have their own proposed packages:
- `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/proposed-packages/symfony-accounting-fr/todo/extract-from-network.md` (FEC, PCG, VAT regimes, IS, liasse data);
- `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/proposed-packages/symfony-accounting-be/todo/extract-from-network.md`.

## Read first

- `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/accounting.md.j2` (overview, target design, open questions), plus the sub-pages `accounting-bank.md.j2`, `accounting-invoices.md.j2` and `accounting-reports.md.j2` in the same folder.
- `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/sources.md.j2` (where the code lives).
- Design rules: run `wex ai::design/rules --formatter php-code` (and `--formatter javascript-code`) in this package.
- Reference package for conventions: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/PACKAGES/PHP/packages/wexample/symfony-money`. It shows an entity on `AbstractEntity`, secure id, `#[PseudocodeExport]`, an API controller with DTO and normalizer, a form processor, and TS assets.

## Prerequisites and dependencies

- `symfony-helpers`: AbstractEntity, AbstractRepository, `PriceHelper`.
- `php-date`.
- `symfony-forms`.
- `symfony-money`: its todo `extract-from-network.md` ports the priced traits. Invoices depend on it, but the bank part does not.
- `symfony-testing`, for the test kernel.

## Decisions already implied (owner and archaeology)

- Money is `int` minor units (cents). Rates are `int` basis points (2000 = 20 %). No floats are stored.
- **No `App\` import anywhere.** Host entities (Organization, User, Project) are reached through interfaces and Doctrine `resolve_target_entities`.
- Country or law specific code goes to `symfony-accounting-fr` / `-be`. The core exposes a jurisdiction interface, generalizing network's `CountryProfileInterface` (`/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Country/`).
- PDF generation is out of scope. PDFs are produced by `SERVICES/local/pdf-factory`. The package only exposes the data a PDF needs.
- Production data is sacred. Never run anything against network's database. Test fixtures copied from network must be anonymized.

## Steps (this file)

1. **Composer.** Declare `phpoffice/phpspreadsheet`, which `XlsBankExportParser` and `FrCa2023BankExportParser` need. Check that `league/csv` is also declared. Add a `tests/` directory with a minimal kernel; copy the pattern from another package that already has one (search with `wex app::source/search --query "TestKernel"`).
2. **Remove the `App\` coupling in `src/Service/AbstractBankExportParser.php`.** It resolves the repository of `App\Entity\AccountingTransaction`. Inject the repository instead, or let the host configure the concrete transaction class through bundle config.
3. **Declare `findSameTransaction(AbstractAccountingTransactionEntity $t): ?AbstractAccountingTransactionEntity` as abstract** in `src/Service/Entity/AbstractAccountingTransactionEntityService.php`, and add it to the interface. Host services already implement it.
4. **Split the Stripe parser.** `src/Service/Stripe2021BankExportParser.php` imports `App\Entity\{AccountingCode,Invoice,Organization,AccountingTransaction}`, `App\Repository\*` and `App\Service\Entity\Invoice*Service`.
   - Keep only the parsing: one amount line and one negated fee line per CSV row.
   - Replace the "STRIPE FEES monthly charge invoice" side effect with an event (`BankFeeImportedEvent` or similar) that the host or the invoice module listens to.
   - The descriptions must match the ones the API import will produce: see the bank todo, which fixes the CSV/API dedup mismatch.
5. **Fix `src/Class/AccountingCollection.php`:**
   - It imports `App\Entity\{Invoice,AccountingTransaction}`. Type against interfaces instead: a document interface and the abstract transaction.
   - `contains()` calls `getId()->equals()`, which is fatal with int ids. Compare with a normalized string key instead, since the key is already built as `(string) getId()`.
6. **Move parsers under `src/Service/Bank/Parser/`**, respecting the design rules for kinds and suffixes. Keep the class names; they are already explicit. Leave a note in the README. Do NOT move the Fr* parsers to `-fr` until the owner answers open question 4 in the knowledge page.
7. **Parser tests.** Port the tests from `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/tests/Unit/Accounting/` (`ParseBankExportLbp2019Test`, `Lbp2023`, `Ca2023`, `Stripe`, `AbstractBankExport`). They run against an in-memory or fake repository, not a database.
   - Fixtures come from `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/tests/Resources/accounting/bank-export-*`.
   - **These files contain a real LBP account number (`0884110P023`), a real CA account, client and people names, and IBAN fragments.** Anonymize them before committing: replace names with `CLIENT A`… and account numbers with fake ones. Keep the structure, the amounts and the line counts.
   - Expected counts: LBP2019 txt 8 lines + 1 statement, LBP2019 csv 103 + 1, LBP2023 26 + 1, CA2023 25 + 1, Stripe 13 payouts + 15 amounts + 15 fees.
8. **Parser bugs.** Fix them in the package with a test each:
   - LBP2019 PDF-text: debits other than `VIREMENT POUR` / `Cotisation Adispo` (PRELEVEMENT, CB) are imported as positive. Use the `Débit`/`Crédit` column context.
   - `TextHelper::getIntDataFromString` breaks on the thousands separator `1.234,56`. Use a parser that is aware of decimal commas.
   - `XlsBankExportParser`: the balance cell `C7` is hard-coded. Make it an overridable method, and stop at the first empty date row.
9. **Update this package's README and `.wex/knowledge`:**
   - `contributing/architecture.md.j2` describes the Stripe parser as coupled to the invoice domain; update it.
   - Add a short "roadmap" section pointing to the other todos.

## Do not

- Do not import from `App\` or copy network's `Wex/BaseBundle`.
- Do not port `src/Pdf/**`.
- Do not copy real bank files, DB dumps or FEC files unmodified.
- Do not run network console commands, tests, migrations or docker. Read the network code only.

## Acceptance criteria

- `composer validate` passes, and the package autoloads with no `App\` reference (`grep -r "App\\\\" src` is empty).
- Parser tests pass with anonymized fixtures, including the three bug-fix tests.
- A transaction-service test covers `saveTransactionIfNotExists` dedup with a fake repository.
- `AccountingCollection` tests cover int and UUID ids, and the fingerprint stability (`I1-I2-T5`).
