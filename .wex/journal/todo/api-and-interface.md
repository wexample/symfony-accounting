# Compta : l'API, les écrans, et les finitions du backend

Opened: 2026-10-09
Author: agent:main

Ce qui reste des trois todos d'extraction (banque, factures, grand livre), closes le 2026-10-09 : le backend est fait, il manque ce qui le montre et l'expose. Lire `done/extract-from-network-{bank,invoices,ledger}.md` pour le détail d'origine, `5a40fb66c266.md` pour les décisions.

## Accès aux dossiers : décidé et fait (2026-10-09)

Décision du propriétaire : par memberships. Fait : `LedgerVoter` (`accounting.view|edit|administer` sur un dossier ou ce qu'il contient), rôle configuré, sinon grant sur le groupe que l'application rattache au dossier (`LedgerGroupResolverInterface`) ; `LedgerAccessService::getAccessible()`. Voir la section « Who may reach a ledger » de l'usage. L'API et les écrans s'appuient dessus.

## API (symfony-api)

Banque (comptes, transactions, allocations, rapprochement), factures (CRUD, émission, relations, PDF), grand livre (comptes, écritures, exercices, rapports).

## Écrans : `symfony-accounting-ds` (package à créer) et `-demo`

- Éditeur de lignes de facture façon tableur, collage multi-lignes.
- Prix et TVA recalculés en direct dans les formulaires de lignes (ex-étape 3 de `symfony-money/…/done/extract-from-network-sweep.md` ; source network `develop-131-fos-user/src/Wex/BaseBundle/Resources/js/forms/priced.ts`), avec `numberFormatMoney` du design system et `RateHelper` côté serveur.
- Tableau des relations mensuelles (le SVG de network) : **à repenser avec le propriétaire**.
- Rapprochement bancaire, transactions, formulaire d'import de relevé (aujourd'hui seulement `BankImportCommand`).
- Balance, bilan, compte de résultat, validation des écritures.

## Finitions du backend (petites, sans décision)

- ~~PDF de facture~~ fait le 2026-10-12 : document par rôles, QR EPC, tampon « payée », forme nationale du compte (RIB FR), avoir citant sa facture, charte sobre, rendu par `symfony-remote-pdf-factory` (`InvoicePdfService`). La charte de marque : `invoice-pdf-brand-charter.md`.
- ~~Test de concurrence de la numérotation~~ fait le 2026-10-12 (`InvoiceNumberingConcurrencyTest`).
- À décider : sur une facture, l'avoir est listé dans les lignes mais ni déduit du total ni du reste à payer (ni du QR).
- Commande `regenerate` de reprise de l'historique : optionnelle.

## À faire relire par un comptable

PCMN et rôles de comptes BE, cases CA3 / CA12 / 2033, mentions légales FR et BE.
