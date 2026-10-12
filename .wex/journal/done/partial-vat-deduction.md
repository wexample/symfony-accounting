# TVA partiellement déductible

Opened: 2026-10-12
Author: agent:main

Aujourd'hui, la TVA est déductible en entier pour un dossier assujetti, et pas du tout pour un dossier non assujetti.

## À faire

- Coefficient de déduction par dossier et par année, avec une valeur provisoire régularisée en fin d'année :
  - FR : assujettissement × taxation × admission ;
  - BE : prorata général, ou affectation réelle.
- Exclusions par nature de dépense : véhicules de tourisme, réception… Un coefficient d'admission par compte, ou un taux sur la ligne d'achat.
- Écriture : la part non déductible va en charge, pas sur le compte de TVA déductible.
- Régularisation annuelle, et sur plusieurs années pour les immobilisations.
- Déclarations : seule la part déductible apparaît.

## Fait (2026-10-12)

- `VatDeductionPolicy` donne le coefficient par année (`vat_deduction_rate`, `vat_deduction_rates`) et l'admission par compte (`vat_admission_rates`).
- À la comptabilisation, l'achat est réparti, qu'il soit facturé ou autoliquidé :
  - la part exclue par l'admission est une charge simple ;
  - la part que le coefficient ne déduit pas est une charge marquée `VatRole::NonDeductible` ;
  - le reste est déductible.
- En TVA sur les encaissements, le paiement ne déplace que la part déductible comptabilisée.
- `VatDeductionRegularizationService` régularise en fin d'année avec le coefficient définitif, contre `VatAdjustmentGain` ou `VatAdjustmentLoss`, et le reporte comme coefficient provisoire de l'année suivante.
- Les routes `api/vat-return/deduction` et `deduction-regularize`.

Pas fait : les régularisations pluriannuelles sur immobilisations (5 ans pour les biens, 20 ans pour l'immobilier).
