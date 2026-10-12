# Documents en devise étrangère

Opened: 2026-10-12
Author: agent:main

Aujourd'hui, un document dans une autre devise que celle du dossier est refusé à l'émission (`InvoiceEmissionService`, « not supported yet »).

## À faire

- Le cours au jour de la facture, conservé sur le document : source à choisir (BCE), via un remote ou une saisie.
- Comptabiliser dans la devise du dossier, en gardant le montant en devise sur les lignes d'écriture.
- Écarts de change au règlement : un gain ou une perte, sur les comptes 666/766 en FR et 654/754 en BE, par `AccountRole`.
- Réévaluation des créances et des dettes en devise à la clôture.
- Rapprochement d'un paiement reçu en devise.
- PDF, UBL et CII : la devise du document. Pour la TVA, la mention dans la devise du dossier (UBL `TaxCurrencyCode`, CII `TaxCurrencyCode`).

## Fait (2026-10-12)

- symfony-money : `ExchangeRate` (conversion exacte en unités mineures), `ExchangeRateProviderInterface`, `ExchangeRateService`.
- symfony-remote-ecb : les cours de référence de la BCE, croisés par l'euro, avec repli sur le dernier jour publié. Vérifié en direct.
- Compta :
  - le taux est figé à l'émission (saisi ou fourni par le fournisseur de taux) ;
  - l'écriture est en devise du dossier, avec le montant en devise sur chaque ligne ;
  - le paiement d'une facture en devise (`Allocation::invoiceAmount`) passe l'écart de change réalisé ;
  - la réévaluation à la clôture est extournée le lendemain ;
  - la TVA est aussi en monnaie nationale sur le PDF, et dans UBL/CII (`TaxCurrencyCode`) ;
  - `currencyCode` et `exchangeRate` passent par l'API.

Pas fait :
- les comptes bancaires en devise : un compte est dans la devise du dossier ;
- la provision pour perte de change en FR (6865/1515) : seul l'écart de conversion est passé.
