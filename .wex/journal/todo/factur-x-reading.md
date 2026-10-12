# Factur-X : lire les factures reçues

Opened: 2026-10-12
Updated: 2026-10-12
Author: agent:main

L'émission est faite : `CiiInvoiceBuilder` écrit le CII (EN 16931), et le PDF d'une facture émise en France est un Factur-X (`FrJurisdiction::isFacturXEmbedded()`). Le XML a été validé contre le XSD et le schematron Factur-X 1.09 EN 16931 (paquet Python `factur-x`, Saxon). Il n'y a aucune erreur, et un seul avertissement : `ApplicableHeaderTradeDelivery` est vide, mais le schéma l'impose.

## Reste

- Lire une Factur-X reçue : extraire `factur-x.xml` du PDF (pièce jointe sous `/AF` et `/Names/EmbeddedFiles`), puis un `CiiInvoiceReader` qui fait ce que fait `UblInvoiceReader` (brouillon d'achat, fournisseur retrouvé ou créé, total contrôlé). Il faut choisir une bibliothèque PDF, ce qui ajoute une dépendance. pdf-factory a refusé `POST /read`.
- La validation par schematron n'est pas dans les tests, parce qu'elle demande Saxon (XSLT 2). Elle est à refaire à la main quand le builder change.
