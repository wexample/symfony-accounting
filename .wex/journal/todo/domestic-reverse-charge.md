# Autoliquidation domestique (construction)

Opened: 2026-10-12
Author: agent:main

L'autoliquidation existe pour l'intracommunautaire (`VatKind::IntraEuServices`, `IntraEuAcquisition`, `ReverseCharge`). Il manque le cas domestique : un fournisseur du même pays qui facture sans TVA, et le client qui l'autoliquide.

## À faire

- Les cas :
  - BE : travaux immobiliers entre assujettis (cocontractant, art. 20) ;
  - FR : sous-traitance dans le BTP (art. 283-2 nonies), et quelques autres cas (déchets, gaz, électricité).
- La juridiction propose le cas selon le contexte (`resolveVatKind`), par exemple un client assujetti du même pays sur une prestation marquée « travaux immobiliers ».
- Mentions légales sur la facture : « Autoliquidation » en FR, « Régime du cocontractant » en BE.
- Déclarations :
  - BE : grilles 45 (vente), 87 et 56 (achat) ;
  - FR : CA3, ligne 2E (base) et ligne 15 (TVA autoliquidée).
- UBL et CII : catégorie `AE`, déjà présente.
