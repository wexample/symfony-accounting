# Peppol : envoyer et recevoir par un point d'accès

Opened: 2026-10-12
Author: agent:main

La compta écrit et lit l'UBL Peppol BIS 3.0 (`UblInvoiceBuilder`, `UblInvoiceReader`, `ElectronicInvoiceReceiver`), mais rien ne le transporte. En Belgique, la facture électronique B2B est obligatoire depuis 2026. En France, les PDP prennent le relais à partir de 2026-2027.

## À faire

- Nouveau package `symfony-remote-peppol` : client d'un point d'accès Peppol (fournisseur à choisir), déclaré comme remote.
  - Envoi d'un UBL.
  - Réception, par webhook ou par polling selon le fournisseur.
  - Suivi des statuts : livré, refusé, message de réponse.
- Recherche du destinataire dans le SML/SMP : est-il joignable par Peppol, avec quel identifiant (`EndpointID`) ?
- Côté compta :
  - à l'émission, l'envoi quand le client est joignable ;
  - à la réception, le passage par `ElectronicInvoiceReceiver` ;
  - le statut d'envoi conservé sur la facture.
- France : vérifier si une PDP s'utilise comme un point d'accès Peppol, ou s'il faut un second remote.

## À décider

Le fournisseur du point d'accès, et si l'on vise aussi les PDP françaises.
