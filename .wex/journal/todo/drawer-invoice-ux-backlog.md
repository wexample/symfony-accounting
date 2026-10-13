# Invoice UX backlog from forgotten issues (status pills, explicit association, fee calculator…)

Opened: 2026-10-09
Updated: 2026-10-09
Author: agent:archeology-drawers

## Read this first — status of this todo

> **This is a proposal for discussion, not an order to code.** It comes from the 2026-10 "bottom of the drawers" archaeology pass. Read it, then discuss it with the owner: every design choice below is to be challenged and validated **before** any code is written. Do not start implementing on your own.
>
> - **Before anything else**: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/PACKAGES/PHP/.wex/local/package-agent-intro.md` — where you stand in the suite, what a package must look like, how to work, and how to judge a drawer todo.
> - Context: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/index.md.j2`, `sources.md.j2`, and the drawers page linked below.
> - Safety: `NETWORK/local/network` runs on production data — read its code only, never run anything against it. Anonymize anything taken from it. Never copy secrets found in its history.

Drawers page: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/drawers-commits.md.j2`, section "Invoice and accounting UX wishes from uncited issues".
Domain pages: `accounting-invoices.md.j2` (fee and relation rules, entity messages) and `accounting-bank.md.j2` (relations board).

## What it is

These are issues that no archaeology page cited, or cited in one line. They are user complaints and wishes about the invoice screens. Each is small. Together they form the UX checklist for the future invoice UI.

## Sources

All in `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/gitlab/issues/`: `038.md`, `042.md`, `045.md`, `066.md`, `068.md`, `069.md`, `076.md`, `078.md`, `253.md`, `260.md`, `285.md`.

## What to recover

1. **Status pills (#38, #66).**
   - A status is a pill filled with the status colour, everywhere, including small search results.
   - Show the status even when it is read-only.
   - When the price is overridden and the recomputed VAT equals the stored VAT up to rounding, do not strike it through.
2. **Explicit association (#45).** The relation form becomes "[Search an input invoice…] (Associate) | (New input invoice)". Selecting a search result only selects it; only the button associates.
3. **Faster member submission (#69).**
   - Expected invoices (`input_expected`) arrive pre-filled, so only the PDF and the invoice code are missing (VAT is known).
   - A "submit for validation" checkbox at creation, with no detour through the full form.
4. **Fresh messages (#76).** Refresh the entity messages after any action (the "missing PDF" warning stays after the PDF is regenerated). An input invoice lists all its outputs.
5. ~~**Quotations are not bills (#253).** Never "late", no client reminder e-mail.~~ Done in the domain: `InvoiceType::isPayable()` leaves quotations out of open documents and dunning.
6. **Gains → invoices (#260).** The member earnings view links each line to its real invoices.
7. **Fee calculator and fee deduction (#78, #68).**
   - Given either the client total or the member input total, show the other amounts: fee (default 3 %, `priceFee`/`priceFeeUnit`), VAT, net.
   - It is the reverse of `createOutputFromInput` / `createInputFromOutput`, described in `accounting-invoices.md.j2`.
   - Plus a "deduct the fee" checkbox on input amounts: 40 € becomes 38.80 €.
   - The issue links a Google Sheet: ask the owner for its formulas instead of guessing them.
8. **Click to copy (#42).** Clicking an invoice id in a list copies it, when the cell is not a link. The helper exists (`copyToClipboard`, see `already-extracted-check.md.j2`).
9. **PDF branding (#285).** Not for this package: forward to `WEXAMPLE/SERVICES/local/pdf-factory`.
   - "Wexample" instead of "Wexample Labs", latest logo, https URL.
   - Full intra-EU VAT number; "reference daily rate" line.
   - Fredoka One for the brand and title; new background.

## Proposed modern shape (suggestion)

- Generic parts belong to the package's UI layer (when it exists) or to the design system: status pill (DS badge with status colour tokens), association widget, refresh of entity messages after actions.
- The fee rules are cooperative-specific. Put the calculator in a pure service (`FeeCalculator::fromClientTotal()` / `fromMemberTotal()`, tested) with a small form widget. Its rate comes from configuration, not a constant.
- Turn each point into an acceptance test once the invoice UI is rebuilt.

## Open questions

1. Is the fee still 3 % and still deducted from the member side? Is the calculator still wanted now that `createInputFromOutput` exists?
2. Which of these UX points already exist in the planned invoice UI (see `extract-from-network*.md` in this journal)?
3. Does #69's "submit for validation at creation" fit the validation workflow decided for the new package?
