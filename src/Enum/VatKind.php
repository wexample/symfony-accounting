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

    /**
     * A distance sale to a consumer in another EU country (goods, or services
     * delivered electronically) once the One-Stop Shop applies: taxed at the
     * customer's country's rate, declared quarterly through the OSS, apart from
     * the national return.
     */
    case Oss = 'oss';

    /** Purchase of goods from another EU country, VAT self-assessed. */
    case IntraEuAcquisition = 'intra_eu_acquisition';

    /** Services bought from another EU country, VAT self-assessed by the buyer. */
    case ReverseCharge = 'reverse_charge';

    /** Services bought outside the EU, VAT self-assessed by the buyer. */
    case ImportedServices = 'imported_services';

    /**
     * Between two VAT-registered companies of the same country, where the law moves
     * the VAT to the buyer: construction works (BE art. 20 AR n° 1, FR art. 283-2
     * nonies CGI). The seller charges none, the buyer self-assesses it.
     */
    case DomesticReverseCharge = 'domestic_reverse_charge';

    /** Exempt by law (medical, education, …). */
    case Exempt = 'exempt';

    /** The seller is not liable for VAT (franchise). */
    case Franchise = 'franchise';

    /** Not in the scope of VAT at all (wages, taxes, transfers). */
    case OutOfScope = 'out_of_scope';
}
