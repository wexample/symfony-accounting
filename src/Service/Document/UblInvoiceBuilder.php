<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use DOMDocument;
use DOMElement;
use Wexample\SymfonyAccounting\Entity\Invoice;

/**
 * The structured electronic invoice: UBL 2.1 following Peppol BIS Billing 3.0
 * (EN 16931), mandatory for B2B invoices in Belgium since 2026 and one of the
 * formats of the French reform. Emitted bills and penalties become Invoice
 * documents (type 380), credit notes CreditNote documents (type 381).
 *
 * Writes what ElectronicInvoiceDataBuilder reads. Sending it (a Peppol access
 * point) is a remote's job, not this one's.
 */
class UblInvoiceBuilder
{
    public const string NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    public const string NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
    public const string NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    public const string NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public function __construct(
        private readonly ElectronicInvoiceDataBuilder $dataBuilder,
    ) {
    }

    public function build(Invoice $invoice): string
    {
        $data = $this->dataBuilder->build($invoice);
        $credit = $data['credit'];
        $currency = $data['currency'];

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->appendChild($xml->createElementNS($credit ? self::NS_CREDIT_NOTE : self::NS_INVOICE, $credit ? 'CreditNote' : 'Invoice'));
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC);

        $this->cbc($root, 'CustomizationID', 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0');
        $this->cbc($root, 'ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
        $this->cbc($root, 'ID', $data['number']);
        $this->cbc($root, 'IssueDate', $data['issueDate']->format('Y-m-d'));

        if ($data['dueDate']) {
            $this->cbc($root, 'DueDate', $data['dueDate']->format('Y-m-d'));
        }

        $this->cbc($root, $credit ? 'CreditNoteTypeCode' : 'InvoiceTypeCode', $data['typeCode']);

        if ($data['note']) {
            $this->cbc($root, 'Note', $data['note']);
        }

        $this->cbc($root, 'DocumentCurrencyCode', $currency);

        if ($data['taxCurrency']) {
            $this->cbc($root, 'TaxCurrencyCode', $data['taxCurrency']);
        }
        $this->cbc($root, 'BuyerReference', $data['buyerReference']);

        if ($data['period']) {
            $period = $this->cac($root, 'InvoicePeriod');
            $this->cbc($period, 'StartDate', $data['period']['start']->format('Y-m-d'));
            $this->cbc($period, 'EndDate', $data['period']['end']->format('Y-m-d'));
        }

        $this->party($this->cac($root, 'AccountingSupplierParty'), $data['seller']);
        $this->party($this->cac($root, 'AccountingCustomerParty'), $data['buyer']);

        if ($payment = $data['payment']) {
            $means = $this->cac($root, 'PaymentMeans');
            $this->cbc($means, 'PaymentMeansCode', '30');
            $this->cbc($means, 'PaymentID', $payment['reference']);
            $account = $this->cac($means, 'PayeeFinancialAccount');
            $this->cbc($account, 'ID', $payment['iban']);
            if ($payment['bic']) {
                $this->cbc($this->cac($account, 'FinancialInstitutionBranch'), 'ID', $payment['bic']);
            }
        }

        if ($data['paymentTerms']) {
            $this->cbc($this->cac($root, 'PaymentTerms'), 'Note', $data['paymentTerms']);
        }

        foreach ($data['allowances'] as $allowanceData) {
            $allowance = $this->cac($root, 'AllowanceCharge');
            $this->cbc($allowance, 'ChargeIndicator', 'false');
            $this->cbc($allowance, 'AllowanceChargeReason', 'Discount');
            $this->money($allowance, 'Amount', $allowanceData['amount'], $currency);
            $this->taxCategory($allowance, 'TaxCategory', $allowanceData['category']);
        }

        $taxTotal = $this->cac($root, 'TaxTotal');
        $this->money($taxTotal, 'TaxAmount', $data['totals']['vat'], $currency);

        foreach ($data['vat'] as $vat) {
            $subtotal = $this->cac($taxTotal, 'TaxSubtotal');
            $this->money($subtotal, 'TaxableAmount', $vat['base'], $currency);
            $this->money($subtotal, 'TaxAmount', $vat['amount'], $currency);
            $this->taxCategory($subtotal, 'TaxCategory', $vat['category'], true);
        }

        if ($data['taxCurrency']) {
            $this->money($this->cac($root, 'TaxTotal'), 'TaxAmount', $data['totals']['vatInTaxCurrency'], $data['taxCurrency']);
        }

        $totalsData = $data['totals'];
        $totals = $this->cac($root, 'LegalMonetaryTotal');
        $this->money($totals, 'LineExtensionAmount', $totalsData['lines'], $currency);
        $this->money($totals, 'TaxExclusiveAmount', $totalsData['net'], $currency);
        $this->money($totals, 'TaxInclusiveAmount', $totalsData['total'], $currency);

        if ($totalsData['allowances']) {
            $this->money($totals, 'AllowanceTotalAmount', $totalsData['allowances'], $currency);
        }

        if ($totalsData['paid']) {
            $this->money($totals, 'PrepaidAmount', $totalsData['paid'], $currency);
        }

        $this->money($totals, 'PayableAmount', $totalsData['payable'], $currency);

        foreach ($data['lines'] as $line) {
            $this->line($root, $line, $credit, $currency);
        }

        return $xml->saveXML();
    }

    private function line(
        DOMElement $root,
        array $data,
        bool $credit,
        string $currency
    ): void {
        $line = $this->cac($root, $credit ? 'CreditNoteLine' : 'InvoiceLine');
        $this->cbc($line, 'ID', $data['id']);
        $this->cbc($line, $credit ? 'CreditedQuantity' : 'InvoicedQuantity', $data['quantity'])->setAttribute('unitCode', $data['unitCode']);
        $this->money($line, 'LineExtensionAmount', $data['net'], $currency);

        if ($discount = $data['discount']) {
            $allowance = $this->cac($line, 'AllowanceCharge');
            $this->cbc($allowance, 'ChargeIndicator', 'false');
            $this->cbc($allowance, 'AllowanceChargeReason', 'Discount');
            if ($discount['percent']) {
                $this->cbc($allowance, 'MultiplierFactorNumeric', $discount['percent']);
            }
            $this->money($allowance, 'Amount', $discount['amount'], $currency);
        }

        $product = $this->cac($line, 'Item');
        if ($data['description']) {
            $this->cbc($product, 'Description', $data['description']);
        }
        $this->cbc($product, 'Name', $data['name']);
        $this->taxCategory($product, 'ClassifiedTaxCategory', $data['category']);

        $this->money($this->cac($line, 'Price'), 'PriceAmount', $data['price'], $currency);
    }

    private function party(
        DOMElement $parent,
        array $data
    ): void {
        $party = $this->cac($parent, 'Party');

        if ($data['endpoint']) {
            $this->cbc($party, 'EndpointID', $data['endpoint']['id'])->setAttribute('schemeID', $data['endpoint']['scheme']);
        }

        $this->cbc($this->cac($party, 'PartyName'), 'Name', $data['name']);

        $postal = $this->cac($party, 'PostalAddress');
        foreach (array_slice(['StreetName', 'AdditionalStreetName'], 0, count($data['addressLines'])) as $index => $name) {
            $this->cbc($postal, $name, $data['addressLines'][$index]);
        }
        if ($data['city']) {
            $this->cbc($postal, 'CityName', $data['city']);
        }
        if ($data['postCode']) {
            $this->cbc($postal, 'PostalZone', $data['postCode']);
        }
        if ($data['country']) {
            $this->cbc($this->cac($postal, 'Country'), 'IdentificationCode', $data['country']);
        }

        if ($data['vatNumber']) {
            $tax = $this->cac($party, 'PartyTaxScheme');
            $this->cbc($tax, 'CompanyID', $data['vatNumber']);
            $this->cbc($this->cac($tax, 'TaxScheme'), 'ID', 'VAT');
        }

        $legal = $this->cac($party, 'PartyLegalEntity');
        $this->cbc($legal, 'RegistrationName', $data['name']);
        if ($data['legalIdentifier']) {
            $this->cbc($legal, 'CompanyID', $data['legalIdentifier']['id']);
        }
    }

    private function taxCategory(
        DOMElement $parent,
        string $name,
        array $category,
        bool $withReason = false
    ): void {
        $element = $this->cac($parent, $name);
        $this->cbc($element, 'ID', $category['id']);

        if (null !== $category['rate']) {
            $this->cbc($element, 'Percent', $category['rate']);
        }

        if ($withReason && $category['code']) {
            $this->cbc($element, 'TaxExemptionReasonCode', $category['code']);
        } elseif ($withReason && $category['reason']) {
            $this->cbc($element, 'TaxExemptionReason', $category['reason']);
        }

        $this->cbc($this->cac($element, 'TaxScheme'), 'ID', 'VAT');
    }

    private function money(
        DOMElement $parent,
        string $name,
        string $amount,
        string $currency
    ): void {
        $this->cbc($parent, $name, $amount)->setAttribute('currencyID', $currency);
    }

    private function cac(
        DOMElement $parent,
        string $name
    ): DOMElement {
        return $parent->appendChild($parent->ownerDocument->createElementNS(self::NS_CAC, 'cac:'.$name));
    }

    private function cbc(
        DOMElement $parent,
        string $name,
        string $value
    ): DOMElement {
        $element = $parent->ownerDocument->createElementNS(self::NS_CBC, 'cbc:'.$name);
        $element->appendChild($parent->ownerDocument->createTextNode($value));

        return $parent->appendChild($element);
    }
}
