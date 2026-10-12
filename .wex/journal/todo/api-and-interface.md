# Compta : l'API, les écrans, et les finitions du backend

Opened: 2026-10-09
Author: agent:main

Ce qui reste des trois todos d'extraction (banque, factures, grand livre), closes le 2026-10-09 : le backend est fait, il manque ce qui le montre et l'expose. Lire `done/extract-from-network-{bank,invoices,ledger}.md` pour le détail d'origine, `5a40fb66c266.md` pour les décisions.

## Accès aux dossiers : décidé et fait (2026-10-09)

Décision du propriétaire : par memberships. Fait : `LedgerVoter` (`accounting.view|edit|administer` sur un dossier ou ce qu'il contient), rôle configuré, sinon grant sur le groupe que l'application rattache au dossier (`LedgerGroupResolverInterface`) ; `LedgerAccessService::getAccessible()`. Voir la section « Who may reach a ledger » de l'usage. L'API et les écrans s'appuient dessus.

## API (symfony-api)

Fait le 2026-10-12 : lecture des dossiers, documents (liste filtrée, fiche, PDF), tiers, comptes bancaires, lignes bancaires (dont celles à rapprocher), allocations, plan comptable, écritures, balance ; écriture des brouillons et de leurs lignes, émission, avoir, suppression d'un brouillon, allocation et son retrait. Voir la section « API » de l'usage, `tests/Integration/ApiTest.php`.

Complétée le 2026-10-12 (même jour) : tiers, brouillons (modification, devis → facture, duplication), import de relevé, rapprochement automatique et propositions, virements internes, saisie et contre-passation d'écritures, lettrage, exercices (contrôle, clôture, réouverture, suivant), TVA (calcul, cases, liquidation), états, grand livre, exports, réglages du dossier. Les écrans en ajouteront au besoin.

## Écrans : `symfony-accounting-ds` (package à créer) et `-demo` — pour l'agent design system

- Éditeur de lignes de facture façon tableur, collage multi-lignes.
- Prix et TVA recalculés en direct dans les formulaires de lignes (ex-étape 3 de `symfony-money/…/done/extract-from-network-sweep.md` ; source network `develop-131-fos-user/src/Wex/BaseBundle/Resources/js/forms/priced.ts`), avec `numberFormatMoney` du design system et `RateHelper` côté serveur.
- Tableau des relations mensuelles (le SVG de network) : **à repenser avec le propriétaire**.
- Rapprochement bancaire, transactions, formulaire d'import de relevé (aujourd'hui seulement `BankImportCommand`).
- Balance, bilan, compte de résultat, validation des écritures.

## Finitions du backend (petites, sans décision)

- Lignes depuis un tableur : le backend est prêt depuis le 2026-10-12 (`api/invoice-item/import`, `api/invoice-item/read-table`). Il manque à l'écran trois choses :
  - le collage d'un tableau dans la grille des lignes : le presse-papier donne du TSV, que le front découpe ;
  - ou le dépôt d'un fichier, lu par `read-table` ;
  - puis un aperçu où l'on choisit la colonne de chaque champ, avec une case pour dire si la première ligne est un en-tête.
  La partie générique (collage, aperçu, choix des colonnes) revient au design system, voir `symfony-design-system/.wex/journal/todo/drawer-editable-grid.md`.
- Factures électroniques : les routes existent depuis le 2026-10-12 (`api/invoice/electronic/{id}?format=ubl|cii`, `api/invoice/receive`). Il manque à l'écran un bouton de téléchargement sur une facture émise, et un dépôt de facture reçue qui ouvre le brouillon d'achat avec ses avertissements.
- ~~PDF de facture~~ fait le 2026-10-12 : document par rôles, QR EPC, tampon « payée », forme nationale du compte (RIB FR), avoir citant sa facture, charte sobre, rendu par `symfony-remote-pdf-factory` (`InvoicePdfService`). La charte de marque : `invoice-pdf-brand-charter.md`.
- ~~Test de concurrence de la numérotation~~ fait le 2026-10-12 (`InvoiceNumberingConcurrencyTest`).
- ~~Avoir sur une facture~~ réglé le 2026-10-12 : la facture reste telle qu'émise ; les avoirs émis sont déduits du montant attendu (`Invoice::calcCreditedAmount()`), donc du reste à payer, des relances, du rapprochement, du QR, et le statut est recalculé à l'émission de l'avoir. Sur le PDF, l'avoir apparaît dans les totaux, avant le reste à payer. À revoir avec la nouvelle version de network.
- Commande `regenerate` de reprise de l'historique : optionnelle.

## À faire relire par un comptable

PCMN et rôles de comptes BE, cases CA3 / CA12 / 2033 (la TVA sur immobilisations remplit la ligne 19 de la CA3 ; sur la CA12, elle reste avec le reste faute d'être sûr de la case), mentions légales FR et BE.
