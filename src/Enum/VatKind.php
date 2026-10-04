<?php

namespace Wexample\SymfonyAccounting\Enum;

/**
 * The tax situation of an operation, which decides the VAT code, the mention
 * printed on the invoice and the box of the VAT return.
 */
enum VatKind: string
{
    /** Domestic operation with VAT. */
    case Domestic = 'domestic';

    /** Goods shipped to a VAT-registered buyer in another EU country. */
    case IntraEuGoods = 'intra_eu_goods';

    /** Services to a VAT-registered buyer in another EU country (reverse charge). */
    case IntraEuServices = 'intra_eu_services';

    /** Outside the EU. */
    case Export = 'export';

    /** Purchase of goods from another EU country, VAT self-assessed. */
    case IntraEuAcquisition = 'intra_eu_acquisition';

    /** Purchase where the buyer pays the VAT instead of the seller. */
    case ReverseCharge = 'reverse_charge';

    /** Exempt by law (medical, education, …). */
    case Exempt = 'exempt';

    /** The seller is not liable for VAT (franchise). */
    case Franchise = 'franchise';

    /** Not in the scope of VAT at all (wages, taxes, transfers). */
    case OutOfScope = 'out_of_scope';
}
