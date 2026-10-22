# Peppol : envoyer et recevoir par un point d'accès

Opened: 2026-10-12
Updated: 2026-10-12
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

## Fait (2026-10-12)

- `symfony-remote-peppol`, indépendant du fournisseur :
  - le vocabulaire : participant, document, transmission, document reçu ;
  - les capacités en interfaces : envoi, boîte de réception, webhook, annuaire ;
  - `FakeAccessPoint` ;
  - la route `/_peppol/webhook/{provider}` et la commande `peppol:collect`, qui deviennent des événements.
- Compta :
  - `PeppolInvoiceService` gère l'envoi d'une vente émise, son suivi (`InvoiceTransmission`) et la réception en brouillon d'achat dans le bon dossier ;
  - `PeppolSubscriber` écoute les événements ;
  - les routes `api/invoice/peppol/{id}` (état et envoi).

## Reste

- Un adaptateur de fournisseur : `symfony-remote-peppol-<fournisseur>`, une fois le fournisseur choisi.
- France : savoir si une PDP se branche comme un point d'accès (mêmes interfaces) ou demande autre chose.
- Envoi automatique à l'émission : non fait volontairement. Un échec réseau ne doit pas faire échouer l'émission ; à reprendre avec une file (messenger) si on le veut.
- Les réponses de l'acheteur (Invoice Response : acceptée, refusée, payée) : un autre type de document, pas encore lu.

## Classé (2026-10-12)

Décision du propriétaire : classé comme fait. L'adaptateur d'un fournisseur viendra quand une application en aura besoin.
