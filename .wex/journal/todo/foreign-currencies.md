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
