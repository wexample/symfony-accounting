# Compta : l'API, les écrans, et les finitions du backend

Opened: 2026-10-09
Author: agent:main

Ce qui reste des trois todos d'extraction (banque, factures, grand livre), closes le 2026-10-09 : le backend est fait, il manque ce qui le montre et l'expose. Lire `done/extract-from-network-{bank,invoices,ledger}.md` pour le détail d'origine, `5a40fb66c266.md` pour les décisions.

## Bloqué par une décision du propriétaire

**Qui accède à quel dossier** (cabinet / client, par `Ledger`) : la forme de l'API et des écrans en dépend.

## API (symfony-api)

Banque (comptes, transactions, allocations, rapprochement), factures (CRUD, émission, relations, PDF), grand livre (comptes, écritures, exercices, rapports).

## Écrans : `symfony-accounting-ds` (package à créer) et `-demo`

- Éditeur de lignes de facture façon tableur, collage multi-lignes.
- Tableau des relations mensuelles (le SVG de network) : **à repenser avec le propriétaire**.
- Rapprochement bancaire, transactions, formulaire d'import de relevé (aujourd'hui seulement `BankImportCommand`).
- Balance, bilan, compte de résultat, validation des écritures.

## Finitions du backend (petites, sans décision)

- PDF de facture (`PdfFactoryDocumentBuilder`, TODO dans le code) : image du QR EPC (`buildEpcQrPayload` existe), variante « payée », grille RIB FR.
- Test de concurrence de la numérotation (deux EntityManager).
- Commande `regenerate` de reprise de l'historique : optionnelle.

## À faire relire par un comptable

PCMN et rôles de comptes BE, cases CA3 / CA12 / 2033, mentions légales FR et BE.
