# Extract bank transactions, allocation, matching and reconciliation from network

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

Give the package the bank half of network's accounting. That means:

- bank lines and statements;
- allocation of lines to documents (invoices), with partial amounts and exclusions;
- internal transfers;
- automatic matching;
- statement reconciliation;
- lettering (grouping documents and payments);
- Stripe ledger import.

Today only the parsers are in the package.

Knowledge:
- `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/accounting-bank.md.j2` (main);
- `accounting.md.j2` (design) in the same folder.

Issues are in `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/gitlab/issues/`: 026, 041, 057, 063, 120, 122, 123, 128, 141, 277, 278, 279, 286.

## Prerequisites

- `extract-from-network.md` (package repair, test kernel) is done.
- For the Stripe API import, `symfony-stripe` must expose a Stripe client. See its todo `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/PACKAGES/PHP/packages/wexample/symfony-stripe/.wex/journal/todo/extract-from-network.md`, step 8.
- The invoice module is NOT required. Allocation targets a `PayableDocumentInterface`, which invoices will implement later.

## Decisions already implied

- A bank line has a signed amount in int cents (+ = money in). A statement is a balance snapshot. Either keep it as a transaction with `type=statement`, as network does, or make it a separate `BankStatement` entity (preferred: clearer queries).
- Allocation (network's `AccountingTransactionRelation`) has these statuses: `exclusion`, `pending_validation`, `validated`. Valid means pending or validated.
  - It stores `partial` and `partialAmount`.
  - Removing an allocation sets `exclusion`, so the auto-matcher never proposes the pair again. **Keep this rule, and do not reproduce network's bug of deleting exclusions on reassign.**
  - Direction consistency: an input (purchase) document goes with a negative line, an output (sale) document with a positive line.
- A transfer is a self-link between two lines with opposite amounts, owned by the negative side.
- The bank itself is either an Organization flagged as a bank (network) or a `BankAccount` entity (IBAN, BIC, label, owner organization). Prefer `BankAccount`. The FR RIB trait stays available.

## Steps

1. **Read the sources:**
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Entity/AccountingTransaction.php`
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Entity/AccountingTransactionRelation.php`
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Repository/AccountingTransactionRepository.php` (521 lines: `findAmountAt`, `findLastStatementBefore`, `queryIsValidTransaction`, `queryNotAssigned`, `queryNotTransferred`)
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Service/Entity/AccountingTransactionEntityService.php` (`findSameTransaction`, `buildEntityMessages`, assign and break methods)
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Service/AccountingTransactionManagerService.php` (`detectRelations`)
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Repository/InvoiceRepository.php` around line 721 (`findMayExpectTransaction`)
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Api/Controller/Entity/AccountingTransactionController.php` and `AccountingTransactionRelationController.php`
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/trees/develop-131-fos-user/src/Api/Controller/Entity/AccountingTransactionController.php` (fos version, with the null-check fix in `itemAssignToTransaction`)
2. **Entities.** Add `BankAccount` (or `BankAccountInterface`), a concrete or abstract `BankTransaction` extending the existing `AbstractAccountingTransactionEntity`, `BankStatement`, and `TransactionAllocation`.
   - `TransactionAllocation` links a transaction to a `PayableDocumentInterface`, with `getDirection()`, `getAmountExpected()` and `getId()`.
   - Add `transfer` / `transferFrom` on the transaction.
   - Give each entity a repository with the queries listed in step 1. Fix these on the way:
     - `queryNotAssigned` (RIGHT join without `IS NULL`);
     - `queryNotTransferred` (checks only the owning side and never creates its builder).
3. **Dedup key.** Network uses an exact match on (bank, description, amount, type, date). Keep that as the default `findSameTransaction`. Add an optional `externalId` column: Stripe `txn_…`, a bank reference when the format has one. When it is set, dedup on it.
4. **Allocation service:**
   - `allocate(transaction, document, ?partialAmount)`
   - `exclude(allocation)`
   - `validate(allocation)`
   - `linkTransfer(a, b)`: only if `a.amount === -b.amount` and neither is linked.
   - `calcAllocatedAmount(document)` and `calcRemaining(document)`

   Port the consistency messages from `AccountingTransactionEntityService::buildEntityMessages` as a `TransactionMessage` value list: `transfer_amounts_should_match`, `not_assigned`, `assigned_amount_exceeded`, `assigned_amount_incomplete`, `paid_amount_matches`.
5. **Matcher.** Define a `TransactionMatcherInterface`.
   - Port network's rule as `ExactAmountMatcher`:
     - candidates are documents expecting payment, with the direction taken from the sign;
     - the expected amount equals `|amount|`;
     - the document date lies within 12 months before the transaction.

     Improvements to make:
     - use the document's expected payment (after loss or fees), not the gross total;
     - add an upper date bound;
     - never mutate the transaction date (network calls `date_modify` on it);
     - keep the "newest wins" behaviour, but return all candidates so the UI can choose.
   - Add `PatternMatcher` per issues #120 and #277: an `AccountingImportPattern` entity (regex on the label → organization, account, VAT, document title). It can propose, or auto-create, a charge document through an event.
   - Matchers only propose allocations in `pending_validation`.
6. **Reconciliation.**
   - `BalanceAtDateService::findAmountAt(bankAccount, date)` = last statement before the date + Σ transactions since.
   - `StatementCheck`: for each statement, compare it with the computed running sum. This is what `front/pages/accounting/vue/reconciliation.vue` does client-side in network; issue #278.
7. **Lettering.**
   - Port `AccountingCollectionsGeneratorService::buildForFiscalYear` (`/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Service/Accounting/AccountingCollectionsGeneratorService.php`) as a connected-components builder over the document ↔ transaction graph, including input ↔ output document relations when the host provides them. It uses the existing `AccountingCollection`.
   - Letters run `A, B, …, Z, AA` per account.
   - The ledger todo will persist the letter on entries. The design reference is dangling commit `80f8fca80`: `git -C /home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/repo.git show 80f8fca80`.
   - The algorithm reference for matching an external ledger (accountant diff) is `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/trees/develop-131-fos-user/src/Command/Accounting/ClementineDiff.php`. Take the ideas: normalized reference matching, greedy multi-entry accumulation, per-account TOTAL_OK/KO. **Do not port its hard-coded 2021 maps.**
8. **Stripe ledger import.**
   - Port `importLastPayments` / `saveNewTransactionFromBalanceTransaction` from `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Wex/BaseBundle/Service/Payment/PaymentStripeService.php` (around line 240+) and the command `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Command/Accounting/StripeImportCommand.php`.
   - Put it in a `StripeBalanceImporter` that uses the `symfony-stripe` client.
   - Fix these network bugs:
     - use the same description format and `externalId` as the CSV parser, because CSV and API currently produce duplicates;
     - report only the lines that are actually new;
     - do not fatal when there is no previous transaction;
     - remove the PHPStan-internal `DateTime` import.
   - Payouts become transfers to the bank account (#141).
9. **API and front.** Expose these endpoints, following the `symfony-api` conventions (look at `symfony-money` `src/Api`):
   - list transactions by account and month;
   - allocate, exclude and transfer;
   - auto-match run;
   - statements CRUD;
   - reconciliation.

   The UI to rebuild is the monthly **relations board**: an SVG canvas where users drag curves between invoice and transaction circles. Source: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/front/vue/accounting/relations.vue` (648 lines), `relations-item.vue` and the scss. Also the reconciliation and transactions lists in `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/front/pages/accounting/vue/`.

   Rewrite them for the current front stack (symfony-loader / design system, see `SERVICES/local/app-board`). This can be a separate later step; open a follow-up todo if needed.
10. **Import form.** Port the import form and processor (bank account, parser choice, file) as a package form plus processor:
    - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Form/TransactionsImportForm.php`
    - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Service/FormProcessor/TransactionsImportFormProcessor.php`

    Parsers are tagged services, not a hard-coded list.

## Do not

- Do not reference Invoice or any `App\` class. Use `PayableDocumentInterface` and events.
- Do not generate accounting entries here. The ledger todo subscribes to allocation events and posts 512 / 411 / 401 / 58 entries.
- Do not port `ClementineDiff` or `importClemDb.sh` as code.
- Do not copy real bank files (anonymize them, see `extract-from-network.md` step 7).

## Acceptance criteria (tests inside the package)

- Allocation:
  - a full payment;
  - two partial allocations on one line;
  - an over-allocation produces an `assigned_amount_exceeded` message;
  - direction mismatch is rejected;
  - exclusion prevents re-proposal by the matcher.
- Transfer: the link is refused when the amounts are not opposite; both sides are resolved.
- ExactAmountMatcher:
  - matches 100.00 ↔ a document expecting 100.00;
  - ignores documents out of the date window or already paid;
  - does not mutate the transaction date.
- PatternMatcher: a regex proposes the configured organization and account.
- `findAmountAt`: a statement plus later lines gives the expected balance. StatementCheck flags a mismatching statement.
- Lettering: two invoices paid by one transfer, plus one invoice paid by two transfers, yields two components with stable fingerprints.
- Stripe importer, with a mocked client: one charge with a fee produces two lines; re-running the import creates nothing; the CSV and API imports of the same `txn` do not duplicate.
