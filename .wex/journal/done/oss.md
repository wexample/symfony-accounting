# OSS : guichet unique de TVA pour les ventes à distance dans l'UE

Opened: 2026-10-12
Author: agent:main

Au-delà de 10 000 € par an de ventes B2C vers d'autres pays de l'UE (biens à distance, services électroniques), la TVA est due au taux du pays du client, et déclarée trimestriellement sur le guichet OSS.

## À faire

- Taux de TVA par pays de l'UE (données, à tenir à jour), et le pays de consommation sur la ligne : il vient de l'adresse du client.
- Le seuil de 10 000 € suivi par dossier et par année civile, avec une alerte à l'approche.
- Comptabilisation : un compte de TVA collectée par pays, ou un compte unique ventilé.
- Déclaration OSS trimestrielle, par pays et par taux, exportable (format du portail national).
- Ces ventes ne vont pas dans la déclaration nationale (CA3 ou grilles BE).

## Fait (2026-10-12)

`VatKind::Oss`, `OssService` :
- le seuil sur l'année en cours et la précédente, ou l'inscription d'office (`oss_registered`) ;
- les biens, et les services électroniques sur option (`oss_electronic_services`) ;
- le taux standard du pays du client (`EuVatRateHelper`), un compte propre (`VatCollectedOss` : 44572 en FR, 4518 en BE) ;
- la déclaration par pays et par taux, son export CSV et sa liquidation, hors de la déclaration nationale ;
- les routes `api/vat-return/oss` et `oss-settle`.

Pas fait : les taux réduits par produit dans l'OSS (seul le taux standard est appliqué), et le format XML propre à chaque portail national.
