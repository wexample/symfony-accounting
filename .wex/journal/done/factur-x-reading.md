# Factur-X : lire les factures reçues

Opened: 2026-10-12
Updated: 2026-10-12
Author: agent:main

L'émission est faite : `CiiInvoiceBuilder` écrit le CII (EN 16931), et le PDF d'une facture émise en France est un Factur-X (`FrJurisdiction::isFacturXEmbedded()`). Le XML a été validé contre le XSD et le schematron Factur-X 1.09 EN 16931 (paquet Python `factur-x`, Saxon). Il n'y a aucune erreur, et un seul avertissement : `ApplicableHeaderTradeDelivery` est vide, mais le schéma l'impose.

## Reste

- Lire une Factur-X reçue : extraire `factur-x.xml` du PDF (pièce jointe sous `/AF` et `/Names/EmbeddedFiles`), puis un `CiiInvoiceReader` qui fait ce que fait `UblInvoiceReader` (brouillon d'achat, fournisseur retrouvé ou créé, total contrôlé). Il faut choisir une bibliothèque PDF, ce qui ajoute une dépendance. pdf-factory a refusé `POST /read`.
- La validation par schematron n'est pas dans les tests, parce qu'elle demande Saxon (XSLT 2). Elle est à refaire à la main quand le builder change.

## Fait (2026-10-12)

- `CiiInvoiceReader` lit le CII, `ElectronicInvoiceImporter` crée le brouillon d'achat, partagé avec `UblInvoiceReader`.
- `FacturXInvoiceReader` passe par le service document-reader (`symfony-remote-document-reader`), avec un avertissement si le XML n'est pas déclaré.
- Testé en aller-retour avec une réponse simulée du service.
- Le 2026-10-12, testé aussi contre les services démarrés (`FacturXTest::testAFacturXRoundTripsThroughTheServices`, lancé seulement si `PDF_FACTORY_URL` et `DOCUMENT_READER_URL` sont définis). Notre Factur-X est rendu par pdf-factory, relu par document-reader, puis enregistré en achat avec le même total et sans avertissement.
- Il n'y a pas encore eu de test sur la facture d'un tiers : la fixture de document-reader porte un CII vide.
