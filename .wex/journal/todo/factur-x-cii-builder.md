# Factur-X : écrire la facture CII

Opened: 2026-10-12
Updated: 2026-10-12
Author: agent:main

Le transport est prêt, il manque le contenu. pdf-factory produit un PDF/A-3b Factur-X à partir d'un XML CII qu'on lui donne (`PdfOutput::facturX($xml, PdfOutput::FACTURX_EN16931)`, dans `symfony-remote-pdf-factory`). La compta écrit UBL (`UblInvoiceBuilder`) et sait le lire, mais n'écrit pas encore CII.

## À faire

- `CiiInvoiceBuilder` : la même facture qu'UBL, en UN/CEFACT CII D16B, profil EN 16931. Les données sont les mêmes que celles d'UBL : il faut un modèle commun, pas une seconde lecture des entités.
- Valider le XML contre le schematron du profil avant de l'envoyer. pdf-factory ne lit pas ce XML.
- Dans `InvoicePdfService`, mettre `output` quand la juridiction le demande (France), probablement par un hook de juridiction plutôt qu'un réglage.
- À vérifier : quelle relationship les plateformes françaises (PDP) attendent pour `EN 16931`. pdf-factory met `Data` par défaut ; si c'est `Alternative`, il faut l'envoyer explicitement.
- Lecture des Factur-X reçues : côté compta, avec une bibliothèque PDF (pièces jointes sous `/AF` et `/Names/EmbeddedFiles`), puis le lecteur CII. pdf-factory a refusé `POST /read`.

## Références

- `SERVICES/local/pdf-factory/.wex/journal/todo/drawer-embedded-metadata.md` : la réponse de pdf-factory.
- `pdf-factory/src/wexample_pdf_factory/resources/schema/factur_x.schema.json`
