# PDF de facture : la charte de marque (style « network »)

Opened: 2026-10-12
Updated: 2026-10-12
Author: agent:main

À finir à la première intégration dans la nouvelle version de la plateforme, qui produira ce style. Rien de la marque n'entre dans les packages : la charte vit dans l'application.

## Ce qui est prêt (les connecteurs)

- Le document générique : `PdfFactoryDocumentBuilder`. Chaque partie est une section nommée par son rôle : parties (issuer, recipient), registration, dates, lines, totals, settlement, notes, payment (QR EPC, titulaire, forme nationale du compte, IBAN, BIC), terms. `meta` porte reference, imprint (l'émetteur en une ligne) et legal.
- La charte générique sobre : `src/Resources/pdf/invoice.css`, reprise de `pdf-factory/examples/invoice/theme.css`.
- La surcharge :
  - `wexample_symfony_accounting.document.stylesheets` : les CSS de l'app, postées après la charte générique (`default_stylesheet: false` pour partir de zéro) ;
  - `InvoiceDocumentEvent` : l'app retouche le document (sections à elle) et le thème (`PdfTheme::withBlock('logo', …)`, `withStylesheet()`) avant l'envoi.
- `InvoicePdfService::prepare()` donne ce qui serait posté, sans rendre : c'est aussi ce qu'il faut pour régénérer les références de pdf-factory.

## À faire

1. Écrire la charte wex sur les rôles génériques. Point de départ : `pdf-factory/examples/network/` (Quicksand en data URI, fond de bulles, bandeau gris du pied) et `pdf-factory/tests/references/network-*`, anonymisés. Ce thème vise aujourd'hui une autre structure (`letterhead`, `registration`, `details`, `summary`, `amounts`, `bank`, `notes`) : le transposer sur les rôles génériques plutôt que l'inverse. N'ajouter par l'événement que ce que le document générique ne dit pas.
2. Le logo en bloc de thème, l'imprint dans le bandeau du pied (`element(meta-imprint)`).
3. Corriger les pétouilles vues dans les références network :
   - mentions de pénalités qui chevauchent le bloc banque ;
   - « Projet : … » coupé sur deux lignes ;
   - pas de QR EPC.
   La grille RIB vide d'un IBAN belge est réglée côté générique : la forme nationale vient de la juridiction et n'existe que pour la France.
4. Demander à l'agent pdf-factory de régénérer `tests/references/invoice-*` depuis `InvoicePdfService::prepare()`, et d'ajouter une référence de la charte wex une fois écrite.

## Références

- Les PDF réels de network : `var/pdf-examples/network/`, ignorés par git (données réelles, ne jamais les publier).
- Le code TCPDF d'origine, en lecture seule : `NETWORK/local/network/src/Pdf/Page/Traits/WexPdfPageTrait.php`, `InvoiceLanding.php`, `InvoiceItems.php`, `front/pdf/partials/`.
