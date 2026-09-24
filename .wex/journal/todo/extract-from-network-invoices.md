# Extract invoicing from network

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

Port network's invoicing into this package under an `Invoice` area. It must not depend on the ledger part, so it could later become its own package (open question 1 in the knowledge page).

Scope:
- documents: bill, quotation, credit note, penalty, charge, product, receipt;
- the input and output directions;
- items;
- relations between documents;
- gap-free numbering at emission;
- the status machine;
- late penalties;
- consistency messages;
- jurisdiction profiles (legal mentions, labels);
- the data contract for PDFs;
- the invoice item editor front-end.

Knowledge:
- `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/accounting-invoices.md.j2` (main);
- `accounting.md.j2` (design) in the same folder.

Issues are in `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/gitlab/issues/`: 008, 028, 031, 054, 064, 073, 113, 135, 137, 218, 272, 273, 281, 282, 309, 312, 318, 323.

## Prerequisites

- `extract-from-network.md` (package repair) is done.
- `symfony-money` pricing traits are ported: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/PACKAGES/PHP/packages/wexample/symfony-money/.wex/journal/todo/extract-from-network.md`. Invoice and item use the **tree model**: parent sums its children, VAT per item, `updatePriceTotal()` on every setter.
- The organization identity (legal identifier, VAT number, country, bank details) comes from the organization domain. See `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/organization.md.j2`. Type against an `InvoicePartyInterface` (name, address, country code, identifier, VAT number, has VAT) implemented by the host Organization.

## Decisions already implied

- **Money:**
  - Amounts are int cents.
  - Quantity is int ×100 (150 = 1.5), with an optional duration string (`1h30m` → days at 7 h/day; hours per day is configurable).
  - VAT is set **per item**, in basis points (#135). Invoice-level VAT only remains a default for new items.
- **Numbering** (2026-07 prod design, the best one):
  - The number is assigned **at emission only**, never at creation.
  - There is one gap-free series per (organization, type, year), with a DB row lock.
  - Format: `<BIL|CRE|PRO|QUO>-<orgPrefix><year>-%04d`. The prefix and pattern must be configurable.
  - Input documents (received from others) keep the number typed by the user.
- **Document types and directions.** Keep network's matrix, but make it a declared configuration: enums plus an allowed-status map. Do not scatter it as constants.
- **Status machine.** Use an explicit transition map or symfony/workflow in place of network's implicit status changes. Base statuses:
  - draft;
  - waiting / validated_manager / validated (the internal approvals are optional: make them configurable);
  - transmitted (emitted);
  - payment_pending;
  - paid (split into paid_client / paid_member only in the app);
  - canceled;
  - model;
  - pending_auto_submit.
  - Add `refused` for quotations (#281).
- **Emission** (`EmissionService`) is the single entry point that:
  - assigns the number;
  - freezes the items;
  - fixes the dates;
  - dispatches an `InvoiceEmittedEvent`, which the host uses to render the PDF through pdf-factory and to post ledger entries.
- **Jurisdiction.** Generalize `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Country/CountryProfileInterface.php` into a tagged `JurisdictionProfileInterface`:
  - identifier label;
  - VAT label;
  - `isCompanyIdentifierIncludedInVatNumber`;
  - header legal mentions (invoice);
  - payment legal mentions (invoice);
  - available VAT rates;
  - late-penalty policy.

  Keep the resolver by party country code and the default fallback. The FR and BE implementations go to the `-fr` / `-be` packages. The core ships only the default profile.

## Steps

1. **Read the sources** (prod = reference for rules):
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Entity/Invoice.php` (1335 lines), `InvoiceItem.php`, `InvoiceRelation.php`, `InvoiceSequence.php`
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Repository/InvoiceRepository.php` (status sets, `TYPES_COMBINATIONS_ALLOWED`, `CODE_PREFIX_BY_TYPE`, `generateSequencedCode`, `previewSequencedCode`, `resolveSeries`, candidate queries), `InvoiceSequenceRepository.php`, `InvoiceRelationRepository.php`
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Service/Entity/InvoiceEntityService.php` (2408 lines; the method map is in the knowledge page), `InvoiceItemEntityService.php`, `Invoice/EmissionEntityService.php`, `Invoice/SubmitEntityService.php`
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Service/FormProcessor/Entity/Invoice/` (18 processors: every creation flow) and `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Form/Entity/Invoice/`
   - `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/src/Country/` (all), `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/front/pdf/invoice.fr.yml` (legal mention texts)
   - `git -C /home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network log -15 --stat` and `git -C /home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network diff` (the 2026-07 "BE Invoices" work)
   - fos pricing version: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/trees/develop-131-fos-user/src/Entity/Invoice.php`, `InvoiceItem.php`, and the tests `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/trees/develop-131-fos-user/tests/Unit/Accounting/Pricing/InvoicePricingTest.php`
2. **Entities:**
   - `Invoice` (mapped superclass or concrete with `resolve_target_entities` for parties, user, project): type, direction, code, title, note (printed), comment (internal), status, issuer party, client party, dateCreated, dateAccounting, dateEmitted (network: `transmitted`), dateDue, datePaid, datePeriodStart/End, currency, discount, override, loss.
   - `InvoiceItem`: title, reference, quantity, duration, unit price, VAT, **position** (missing in network, #54).
   - `InvoiceRelation`: from, to, type (`quotation`, `credit`, `penalty`, `rebill`), optional partial amount. Network calls the rebill type `output` and the penalty type `exceeding`: rename them.
   - `InvoiceSequence`: organization, type, year, lastNumber, with a unique constraint.

   Do NOT port `ORGANIZATIONS_EXEMPTED_PDF`, the hard-coded org ids, or the `TYPE_TAX` "tax invoices". Tax declarations belong to the ledger / `-fr` todos.
3. **`InvoiceSequenceService`:**
   - `next(org, type, year)` uses a pessimistic lock in a transaction;
   - `peek()`;
   - `format()` is configurable.

   Fix network's issues:
   - take the year from the accounting date, not the creation date;
   - never set a legacy code at creation;
   - make credit notes use the CRE series, with direction output, because network created them as input by mistake.
4. **Status machine and `EmissionService`.**
   - All status changes go through one service. In network, the API `editField`, `BillController::submit` / `validate` and mail sending bypass emission; do not repeat that.
   - Once emitted, items are read-only, and the item endpoint must check ownership and editability **before** saving (network checked after saving).
5. **Factories and flows** (`InvoiceFactory` and services):
   - `createQuotation`, `convertQuotationToBill` (clones the items, adds the relation);
   - `createBillFromQuotation`, `createQuotationFromBill`;
   - `createCreditNote(bill, items|amount)` (the credit appears as a negative line on the bill's PDF data);
   - `duplicate(invoice)` (#31: data and items, no relations);
   - `createFromModel(model)` (recurring contract invoices, driven by the host).

   The member-rebilling flows (input → output + fee) stay in the app, but expose the generic pieces the app needs:
   - `copyItems(from, to)` (#265);
   - `calcUnassignedAmount` for "rebill" relations.
6. **Late penalties.** `LatePenaltyCalculator(invoice, paidDate, policy)`:
   - Network rule: one item per late month, day rate = 10 %/month × total / days in the month, plus a 40 € flat fee.
   - Accountant rule (#28): total × annual rate × days / 365.
   - Make the policy come from the jurisdiction profile, and implement both. Due date = emission + payment term (default 30 days; network used 1 month).
   - Fix network's bug: the constructor sets `transmitted` and `datePaid` to now, so penalties can come out as zero.
7. **Consistency messages.** Port `buildEntityMessages` from `InvoiceEntityService` as an `InvoiceChecker` returning typed messages with a severity. Keep the generic checks:
   - missing PDF / document;
   - negative total;
   - status not allowed;
   - overpaid;
   - fully paid but not paid;
   - payment on the wrong status;
   - overdue;
   - partial payment;
   - credit not consumed;
   - accounting account missing.

   App-specific checks (output − inputs − fee, member VAT regime) stay in the app as extra checker services (tagged).
8. **Jurisdiction.** Add the interface, resolver, default profile and Twig function `invoice_jurisdiction(party)`. Leave FR and BE to their packages. Provide a `InvoiceDocumentData` builder: the full data contract for pdf-factory. The layout spec is in the knowledge page, section "PDF layout spec". It contains:
   - title key;
   - issuer and client blocks;
   - period;
   - items;
   - credit lines;
   - totals per VAT rate;
   - legal mentions;
   - bank block;
   - footer.
9. **Payments integration.** Invoice implements the bank todo's `PayableDocumentInterface`:
   - direction;
   - amount expected = final − loss;
   - `calcPaidAmount` / `calcRemaining` via allocations;
   - `markPaid()`.
10. **API.** Following the `symfony-api` conventions (see `symfony-money` `src/Api`):
    - list by party, project or year;
    - get;
    - edit fields;
    - items collection POST (bulk save of the item list);
    - relations;
    - transitions (submit, validate, emit, cancel);
    - number preview.
11. **Front-end: the invoice item editor.** Port it to the current front stack; check `wex ai::design/rules --formatter javascript-code` and `SERVICES/local/app-board` for patterns. Source files (Vue 2.6, identical in every branch), all under `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/`:
    - `front/pages/entity/invoice/tabs/edit/vue/items.vue` + `.vue.twig`
    - `front/pages/entity/invoice/tabs/edit/vue/partials/invoice-item.vue` + twig
    - `front/vue/entities-list-editable.vue`
    - `src/Wex/BaseBundle/Resources/js/vue/entity/default/list.vue`
    - `front/vue/entity/entity-having-quantity-or-duration.vue`
    - `front/vue/entity/partials/invoice-item/quantity-editable.vue`
    - `front/vue/entity/partials/field-editable.vue`
    - `src/Wex/BaseBundle/Resources/js/helpers/DurationsHelper.ts`
    - `front/vue/entity/partials/invoice/table-total.vue.twig`
    - `front/vue/entity/partials/invoice/status-drop-down.vue`
    - `front/pages/entity/invoice/edit.ts`

    Features to keep:
    - inline rows, with a blank row always appended;
    - Enter/Escape navigation;
    - soft delete;
    - bulk save;
    - unsaved-changes guard (hash of the items);
    - multi-line **paste from a spreadsheet** (PapaParse) that fills rows and columns (#282 says it regressed; fix it);
    - quantity or duration toggle;
    - live totals (HT, discount, VAT per rate, TTC, override).

    Add what network lacks:
    - a VAT column per item (#135);
    - drag-to-reorder (#54).

## Do not

- Do not port `src/Pdf/**`, `InvoicePdfService` or TCPDF code. Only build the data contract.
- Do not port cooperative logic: member input bills re-billed with a 3 % fee, `calcToDispatch`, benefit groups, `paid_member`. It is app-specific. Keep hooks (relation type `rebill`, checker tags) so the app can add it.
- Do not port VAT/IS "tax invoices" (`TYPE_TAX`, monthly VAT relations, `VAT-3517`, `TAX-2065`).
- Do not port `generateInvoiceFromCart` as-is. The cart todo notes that it double-counts quantity and drops item VAT. Provide a generic `createFromLines()` instead, and let the app listen to `CartPaidEvent`.
- Do not port the fos signature (#90) or PDF-editor prototypes.
- Do not import `App\`.

## Acceptance criteria (tests inside the package)

- Pricing: port `InvoicePricingTest` from fos (subtotal, money and percent discount, per-item VAT, override). A multi-rate invoice gives the correct totals per VAT rate.
- Numbering:
  - two emissions give `…-0001`, `…-0002`;
  - the series is separate per type and per year;
  - a draft has no number;
  - `peek()` does not consume a number;
  - a concurrency test (two entity managers) gets distinct numbers, or the behaviour is documented if SQLite cannot lock.
- Status machine: forbidden transitions throw; emission is the only path that assigns the number; items are locked after emission.
- Quotation → bill conversion copies items and creates the relation. A credit note appears in the bill's document data as negative lines.
- Late penalty: both policies are computed for a bill 45 days late (fixed dates).
- Jurisdiction: the resolver picks the profile by country code, with default fallback. A fake profile's mentions appear in `InvoiceDocumentData`.
- Checker: overpaid, overdue and partial-payment cases produce the expected messages.
