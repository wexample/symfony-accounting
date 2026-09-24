# Extract the double-entry ledger, fiscal year and reports from network

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

Give the package a generic double-entry ledger:

- chart of accounts;
- journals;
- entries with lettering;
- fiscal years with closing and carry-forward;
- a report engine (trial balance, general ledger, balance sheet and income statement driven by account-pattern categories);
- posting from invoices and bank allocations;
- an exporter interface. The FEC implementation lives in `symfony-accounting-fr`.

Knowledge:
- `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/accounting-reports.md.j2` (main);
- `accounting.md.j2` (design) in the same folder.

Issues are in `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/gitlab/issues/`: 056 (the main accounting backlog), 071, 080, 126, 286.

## Prerequisites

- `extract-from-network.md` is done.
- `extract-from-network-bank.md` and `extract-from-network-invoices.md` are done, or at least their events and interfaces exist. The ledger subscribes to `InvoiceEmittedEvent` and to allocation and transfer events.

## Decisions already implied

- **Post at events, lock at close** (recommended; the owner may still arbitrate, see open question 2 in the knowledge page).
  - Network regenerates every entry at each close. That is the cause of its "close twice" bug and of the stale caches.
  - Here, entries are posted when a document is emitted or a payment is allocated.
  - A closed fiscal year rejects new or changed entries.
  - Keep a `regenerate` command only to migrate history.
- **Fiscal year** has start and end dates, so non-calendar and long first exercises work (network was calendar-only). Status: open, closing, closed.
- **Accounts.** The number is a string of digits (network used an int padded to 6). Fields: label, nature (debit/credit/both), counterpart account (for example 706 → 411), source dataset (e.g. `fr_pcg`, `fr_pca`, `be_pcmn`, `custom`).
  - The chart datasets ship with the jurisdiction packages, not here.
  - Auxiliary (sub-ledger) accounts per party are first-class: `C<PARTY>` / `F<PARTY>` style, as the accountant expects. Network lacked them, which broke its FEC.
- **Report categories** use pattern relations:
  - an account prefix;
  - an optional sign condition (a debit balance goes to assets and a credit balance to liabilities, for accounts such as 512 or 44x);
  - exclusions, which must be honoured;
  - no overlapping prefixes.

  Network's version double-counted accounts and ignored exclusions and depreciation (#56).
- Amounts are int cents in `debit` / `credit` columns, and exactly one of them is non-zero per line.

## Steps

1. **Read the sources** (logic is identical in current, fos and s3; use current):
   - Under `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Entity/`: `AccountingCode.php`, `AccountingCategory.php`, `AccountingCategoryRelation.php`, `AccountingEntry.php`, `AccountingFiscalYear.php`
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Service/Entity/AccountingEntryEntityService.php` (`saveEntriesFromInvoice`, `saveEntityFromTransaction`, `isEntriesBalancedForEntity`)
   - Under `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Service/Accounting/`: `ExerciseGenerator.php`, `ExerciseManager.php`, `ExerciseValidator.php`, `BalanceGenerator.php`, `BalanceSheetGenerator.php`, `IncomeStatementGenerator.php`
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Service/Entity/AccountingFiscalYearEntityService.php` (`close()` pipeline)
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Entity/NotOrm/` (AccountingBalance, AccountingTableSection, AccountingTableTotalGroup)
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Command/Accounting/FiscalYearExportGeneralLedgerCommand.php` and `AbstractFiscalYearEntryExportCommand.php` (the `--group=organization` aux-account logic)
   - Lettering column design: `git -C /home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/repo.git show 80f8fca80` and `63e7433a2` (dangling, never merged)
   - Category seed: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/backup/various/migrations/Version20210217095238.php` (pattern → category logic)
2. **Entities:**
   - `AccountingAccount`;
   - `AccountingJournal` (code + label; the defaults are BQ bank, VE sales, AC purchases, OD misc, AN opening, CA cash);
   - `AccountingEntry`: journal, number (sequential per fiscal year and journal), date, fiscal year, account, debit, credit, label, document reference (type + id + human ref), `letter` + `dateLettered`, `validatedAt`;
   - `FiscalYear`;
   - `AccountingReportCategory` + `AccountingReportCategoryPattern`.
3. **`PostingService`:**
   - `post(EntryBatch)` checks the batch balances and that the fiscal year is open, then assigns numbers.
   - Invoice posting listener, porting `saveEntriesFromInvoice`:
     - income or expense account (the item or invoice account, default from configuration: FR 706/604);
     - VAT per rate (collected or deductible account, from the jurisdiction);
     - counterpart on the party's auxiliary account (411/401 + aux);
     - bad-debt lines when a loss is set (network: 416 / 411 / 68174 / 491).
   - Allocation posting listener, porting `saveEntityFromTransaction`:
     - bank account (512 + bank sub-account) against the party account;
     - **one counterpart line per payment** (#286);
     - transfers via the internal-transfer account (58).
   - Credit notes are posted reversed.
   - Relations of type "financing" are excluded from posting (#126).
4. **Lettering.** Use the bank todo's connected-components builder: letter the entries of each party account whose document and payment group balances to zero; unletter on change.
5. **Fiscal year closing.** `FiscalYearCloser`:
   - pre-checks, porting `ExerciseValidator` and `InvoiceEntityService::messagePreventFiscalYearClosing`. The checks are pluggable (tagged) so the host can add its own;
   - compute the result (Σ class-7 net − Σ class-6 net). **Use net balances**; network ignored class-7 debits and class-6 credits;
   - carry-forward entries into N+1 on journal AN: balances of balance-sheet accounts plus the result to "retained earnings". Which accounts count as balance-sheet accounts and which accounts receive the result come from the jurisdiction; FR is 1–5, with 120/129 → 110/119;
   - lock.

   Fix network's bug: the carry-forward read a cached balance that was computed *after* it.
6. **Report engine:**
   - `TrialBalance`: per account, debit, credit and net, plus totals per class. `isBalanced()`.
   - `GeneralLedger`: per account, the entries with a running balance and the letters. Options: group by party auxiliary account; filter by journal or bank.
   - `CategoryReport`: balance sheet and income statement from categories and patterns, with groups and total patterns (network's `calculatePattern`, which evaluates `I-II+III`).

     Fix the income statement formulas: network never counted group VII, group VIII cancelled itself, and `products_total` used VIII.

     Test the category mapping against the expected 2033 values in `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/trees/develop-131-fos-user/tests/Resources/accounting/2018/pdf-2033.json` and `2019/pdf-2033.json` (FR data; the actual mapping ships in `-fr`, so a test here can use a small fake mapping).
   - Reports are computed on demand. Optionally cache them as snapshots at close, with JSON, not the PHP-serialized `OBJECT` columns network used.
7. **Exporter interface.**
   - `LedgerExporterInterface` with `export(FiscalYear, options): iterable|stream`.
   - Generic CSV exports (entries, general ledger), porting `FiscalYearExportCsvEntryCommand` and `FiscalYearExportGeneralLedgerCommand` without their fake per-invoice letters.
   - FEC goes to `-fr`.
8. **Import (new, owner need).** The owner will soon have a client who processes FEC files. Design an `EntryImporterInterface` so that `-fr` can provide a FEC importer (FEC file → fiscal year + accounts + entries), and write a generic CSV importer here.
9. **API and front.** Expose these endpoints:
   - accounts (CRUD, custom accounts);
   - entries list and filter;
   - fiscal years (create, close);
   - reports.

   Screens to rebuild later: balance, balance sheet, income statement, validate. The network pages are in `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/front/pages/accounting/`.

## Do not

- Do not port the regenerate-everything-at-close pipeline as the main flow.
- Do not port VAT/IS "tax invoices", the FR tax schedules or the rates. They belong to `-fr`.
- Do not port `AccountingJournalItem` (dead code), the PDF form classes (`src/Pdf/Accounting20xx`), the PHP-serialized metadata caches, or hard-coded years.
- Do not put FR account numbers in core code. Defaults come from the jurisdiction or configuration.
- Do not copy the owner's real FEC files or DB dumps into the package.

## Acceptance criteria (tests inside the package)

- Posting:
  - an unbalanced batch is rejected;
  - posting into a closed year is rejected;
  - a sale posted at 20 % VAT produces 3 lines that balance;
  - a partial payment of a sale produces bank / party lines;
  - a transfer between two banks passes through the transfer account;
  - two invoices paid by one line give one counterpart line per payment.
- Lettering: the party account lines of a fully paid invoice share a letter; a partial payment leaves them unlettered.
- Closing (fixed small dataset):
  - the result is computed with net balances;
  - the carry-forward entries in N+1 balance to zero;
  - the retained-earnings account receives the result;
  - closing twice is idempotent;
  - no entries can be posted after close.
- Reports:
  - the trial balance is balanced;
  - a category report with overlapping-free patterns and one exclusion gives the expected totals;
  - an income-statement pattern including VII computes correctly;
  - the general ledger's running balance is correct.
- Non-calendar fiscal year (e.g. 2025-07-01 → 2026-06-30): entries fall into the right year.
