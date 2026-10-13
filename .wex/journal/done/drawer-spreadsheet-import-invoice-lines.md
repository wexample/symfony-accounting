# Import invoice lines from a spreadsheet with column mapping (2018 ancestor)

Opened: 2026-10-09
Updated: 2026-10-09
Author: agent:archeology-drawers

## Read this first — status of this todo

> **This is a proposal for discussion, not an order to code.** It comes from the 2026-10 "bottom of the drawers" archaeology pass. Read it, then discuss it with the owner: every design choice below is to be challenged and validated **before** any code is written. Do not start implementing on your own.
>
> - **Before anything else**: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/PACKAGES/PHP/.wex/local/package-agent-intro.md` — where you stand in the suite, what a package must look like, how to work, and how to judge a drawer todo.
> - Context: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/index.md.j2`, `sources.md.j2`, and the drawers page linked below.
> - Safety: `NETWORK/local/network` runs on production data — read its code only, never run anything against it. Anonymize anything taken from it. Never copy secrets found in its history.

Drawers page: `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/local/network/.wex/knowledge/readme/archeology/drawers-outside.md.j2`

## What it is

The 2018 ancestor app `invoices` could fill an invoice's lines from a Google Sheet. The later #282 asked for paste-from-spreadsheet (screencast `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/archeo/gitlab/uploads/282-1e5c6543-0001-0209_conv.webm`, 17 s: rows copied in a sheet, Ctrl+V in the incoming-invoice Items tab adds one row per line). See also `drawer-invoice-ux-backlog.md` in this journal.

## Where

- `/home/weeger/Desktop/WIP/WEB/WEXAMPLE/NETWORK/backup/invoices/project/pages/invoice-import-sheet.inc.php` (2018-03-07) and `project/class/WexGoogleLoaderSheet.php` (2018-04-09). Last commit of that repo `7901ab1` 2018-07-01. `project/conf/googleDriveClientSecret.json` is a secret: do not copy.

## How it worked

The user pastes a sheet URL → Google OAuth → the first sheet's header row `A1:L1` is read → the user maps three fields to columns: `title` ("Titre"), `price` ("Prix (TJM)"), `quantity` ("Quantity (Durée)") → rows `A2:L1000` become invoice items.

## Proposed shape (suggestion)

A provider-free version: paste (TSV from clipboard) or upload CSV → column mapping step (title, unit price, quantity, VAT) with a preview → items created. Generic part (paste/CSV + mapping UI) in `symfony-forms` / design system editable grid (see `symfony-design-system/.wex/journal/todo/drawer-editable-grid.md`); the item mapping in `symfony-accounting`.

## Open questions

1. Paste only, CSV upload, or Google Sheets too?

## Backend fait (2026-10-12, agent:main)

Décision du propriétaire : le collage et les fichiers, Google Sheets plus tard (une feuille publique s'exporte en CSV). Le front découpe le collage, les fichiers passent par document-reader. Côté backend :

- `InvoiceLineImportService` prend des lignes et la colonne de chaque champ.
- `api/invoice-item/import` ajoute les lignes ; `api/invoice-item/read-table` rend les tableaux d'un fichier.

Ce qui reste pour l'écran est dans `api-and-interface.md`.
