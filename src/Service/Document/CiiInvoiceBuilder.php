<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use DateTimeInterface;
use DOMDocument;
use DOMElement;
use Wexample\SymfonyAccounting\Entity\Invoice;

/**
 * The structured electronic invoice in UN/CEFACT CII (D16B), profile EN 16931:
 * the XML a Factur-X PDF embeds, one of the formats of the French reform. The
 * same invoice as UblInvoiceBuilder writes, read by ElectronicInvoiceDataBuilder.
 *
 * Elements follow the order the CII schema imposes. Validate the output against
 * the Factur-X EN 16931 schematron before going live.
 */
class CiiInvoiceBuilder
{
    public const string NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    public const string NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    public const string NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
    public const string NS_QDT = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';

    public const string GUIDELINE_EN16931 = 'urn:cen.eu:en16931:2017';

    public function __construct(
        private readonly ElectronicInvoiceDataBuilder $dataBuilder,
    ) {
    }

    public function build(Invoice $invoice): string
    {
        $data = $this->dataBuilder->build($invoice);
        $currency = $data['currency'];

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->appendChild($xml->createElementNS(self::NS_RSM, 'rsm:CrossIndustryInvoice'));
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:qdt', self::NS_QDT);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', self::NS_RAM);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', self::NS_UDT);

        $context = $this->rsm($root, 'ExchangedDocumentContext');
        $this->ram($this->ram($context, 'GuidelineSpecifiedDocumentContextParameter'), 'ID', self::GUIDELINE_EN16931);

        $document = $this->rsm($root, 'ExchangedDocument');
        $this->ram($document, 'ID', $data['number']);
        $this->ram($document, 'TypeCode', $data['typeCode']);
        $this->date($this->ram($document, 'IssueDateTime'), $data['issueDate']);
        if ($data['note']) {
            $this->ram($this->ram($document, 'IncludedNote'), 'Content', $data['note']);
        }

        $transaction = $this->rsm($root, 'SupplyChainTradeTransaction');

        foreach ($data['lines'] as $line) {
            $this->line($transaction, $line, $currency);
        }

        $agreement = $this->ram($transaction, 'ApplicableHeaderTradeAgreement');
        $this->ram($agreement, 'BuyerReference', $data['buyerReference']);
        $this->party($this->ram($agreement, 'SellerTradeParty'), $data['seller']);
        $this->party($this->ram($agreement, 'BuyerTradeParty'), $data['buyer']);

        // Mandatory, even when the invoice says nothing about the delivery.
        $this->ram($transaction, 'ApplicableHeaderTradeDelivery');

        $settlement = $this->ram($transaction, 'ApplicableHeaderTradeSettlement');
        if ($payment = $data['payment']) {
            $this->ram($settlement, 'PaymentReference', $payment['reference']);
        }
        if ($data['taxCurrency']) {
            $this->ram($settlement, 'TaxCurrencyCode', $data['taxCurrency']);
        }
        $this->ram($settlement, 'InvoiceCurrencyCode', $currency);

        if ($payment) {
            $means = $this->ram($settlement, 'SpecifiedTradeSettlementPaymentMeans');
            $this->ram($means, 'TypeCode', '30');
            $this->ram($this->ram($means, 'PayeePartyCreditorFinancialAccount'), 'IBANID', $payment['iban']);
            if ($payment['bic']) {
                $this->ram($this->ram($means, 'PayeeSpecifiedCreditorFinancialInstitution'), 'BICID', $payment['bic']);
            }
        }

        foreach ($data['vat'] as $vat) {
            $tax = $this->ram($settlement, 'ApplicableTradeTax');
            $this->ram($tax, 'CalculatedAmount', $vat['amount']);
            $this->ram($tax, 'TypeCode', 'VAT');
            if ($vat['category']['reason']) {
                $this->ram($tax, 'ExemptionReason', $vat['category']['reason']);
            }
            $this->ram($tax, 'BasisAmount', $vat['base']);
            $this->ram($tax, 'CategoryCode', $vat['category']['id']);
            if ($vat['category']['code']) {
                $this->ram($tax, 'ExemptionReasonCode', $vat['category']['code']);
            }
            if (null !== $vat['category']['rate']) {
                $this->ram($tax, 'RateApplicablePercent', $vat['category']['rate']);
            }
        }

        if ($data['period']) {
            $period = $this->ram($settlement, 'BillingSpecifiedPeriod');
            $this->date($this->ram($period, 'StartDateTime'), $data['period']['start']);
            $this->date($this->ram($period, 'EndDateTime'), $data['period']['end']);
        }

        foreach ($data['allowances'] as $allowanceData) {
            $allowance = $this->allowance($settlement, $allowanceData['amount']);
            $this->tradeTax($allowance, 'CategoryTradeTax', $allowanceData['category']);
        }

        if ($data['paymentTerms'] || $data['dueDate']) {
            $terms = $this->ram($settlement, 'SpecifiedTradePaymentTerms');
            if ($data['paymentTerms']) {
                $this->ram($terms, 'Description', $data['paymentTerms']);
            }
            if ($data['dueDate']) {
                $this->date($this->ram($terms, 'DueDateDateTime'), $data['dueDate']);
            }
        }

        $totalsData = $data['totals'];
        $totals = $this->ram($settlement, 'SpecifiedTradeSettlementHeaderMonetarySummation');
        $this->ram($totals, 'LineTotalAmount', $totalsData['lines']);
        if ($totalsData['allowances']) {
            $this->ram($totals, 'AllowanceTotalAmount', $totalsData['allowances']);
        }
        $this->ram($totals, 'TaxBasisTotalAmount', $totalsData['net']);
        $this->ram($totals, 'TaxTotalAmount', $totalsData['vat'])->setAttribute('currencyID', $currency);
        if ($data['taxCurrency']) {
            $this->ram($totals, 'TaxTotalAmount', $totalsData['vatInTaxCurrency'])->setAttribute('currencyID', $data['taxCurrency']);
        }
        $this->ram($totals, 'GrandTotalAmount', $totalsData['total']);
        if ($totalsData['paid']) {
            $this->ram($totals, 'TotalPrepaidAmount', $totalsData['paid']);
        }
        $this->ram($totals, 'DuePayableAmount', $totalsData['payable']);

        return $xml->saveXML();
    }

    private function line(
        DOMElement $transaction,
        array $data,
        string $currency
    ): void {
        $line = $this->ram($transaction, 'IncludedSupplyChainTradeLineItem');
        $this->ram($this->ram($line, 'AssociatedDocumentLineDocument'), 'LineID', $data['id']);

        $product = $this->ram($line, 'SpecifiedTradeProduct');
        $this->ram($product, 'Name', $data['name']);
        if ($data['description']) {
            $this->ram($product, 'Description', $data['description']);
        }

        $agreement = $this->ram($line, 'SpecifiedLineTradeAgreement');
        $this->ram($this->ram($agreement, 'NetPriceProductTradePrice'), 'ChargeAmount', $data['price']);

        $this->ram($this->ram($line, 'SpecifiedLineTradeDelivery'), 'BilledQuantity', $data['quantity'])->setAttribute('unitCode', $data['unitCode']);

        $settlement = $this->ram($line, 'SpecifiedLineTradeSettlement');
        $this->tradeTax($settlement, 'ApplicableTradeTax', $data['category']);

        if ($discount = $data['discount']) {
            $this->allowance($settlement, $discount['amount'], $discount['percent']);
        }

        $this->ram($this->ram($settlement, 'SpecifiedTradeSettlementLineMonetarySummation'), 'LineTotalAmount', $data['net']);
    }

    private function party(
        DOMElement $party,
        array $data
    ): void {
        $this->ram($party, 'Name', $data['name']);

        if ($data['legalIdentifier']) {
            $id = $this->ram($this->ram($party, 'SpecifiedLegalOrganization'), 'ID', $data['legalIdentifier']['id']);
            if ($data['legalIdentifier']['scheme']) {
                $id->setAttribute('schemeID', $data['legalIdentifier']['scheme']);
            }
        }

        $address = $this->ram($party, 'PostalTradeAddress');
        if ($data['postCode']) {
            $this->ram($address, 'PostcodeCode', $data['postCode']);
        }
        foreach (array_slice(['LineOne', 'LineTwo'], 0, count($data['addressLines'])) as $index => $name) {
            $this->ram($address, $name, $data['addressLines'][$index]);
        }
        if ($data['city']) {
            $this->ram($address, 'CityName', $data['city']);
        }
        if ($data['country']) {
            $this->ram($address, 'CountryID', $data['country']);
        }

        if ($data['endpoint']) {
            $this->ram($this->ram($party, 'URIUniversalCommunication'), 'URIID', $data['endpoint']['id'])->setAttribute('schemeID', $data['endpoint']['scheme']);
        }

        if ($data['vatNumber']) {
            $this->ram($this->ram($party, 'SpecifiedTaxRegistration'), 'ID', $data['vatNumber'])->setAttribute('schemeID', 'VA');
        }
    }

    private function allowance(
        DOMElement $parent,
        string $amount,
        ?string $percent = null
    ): DOMElement {
        $allowance = $this->ram($parent, 'SpecifiedTradeAllowanceCharge');
        $indicator = $this->ram($allowance, 'ChargeIndicator');
        $indicator->appendChild($parent->ownerDocument->createElementNS(self::NS_UDT, 'udt:Indicator', 'false'));
        if ($percent) {
            $this->ram($allowance, 'CalculationPercent', $percent);
        }
        $this->ram($allowance, 'ActualAmount', $amount);
        $this->ram($allowance, 'Reason', 'Discount');

        return $allowance;
    }

    private function tradeTax(
        DOMElement $parent,
        string $name,
        array $category
    ): void {
        $tax = $this->ram($parent, $name);
        $this->ram($tax, 'TypeCode', 'VAT');
        $this->ram($tax, 'CategoryCode', $category['id']);

        if (null !== $category['rate']) {
            $this->ram($tax, 'RateApplicablePercent', $category['rate']);
        }
    }

    private function date(
        DOMElement $parent,
        DateTimeInterface $date
    ): void {
        $parent->appendChild($parent->ownerDocument->createElementNS(self::NS_UDT, 'udt:DateTimeString', $date->format('Ymd')))
            ->setAttribute('format', '102');
    }

    private function rsm(
        DOMElement $parent,
        string $name
    ): DOMElement {
        return $parent->appendChild($parent->ownerDocument->createElementNS(self::NS_RSM, 'rsm:'.$name));
    }

    private function ram(
        DOMElement $parent,
        string $name,
        ?string $value = null
    ): DOMElement {
        $element = $parent->ownerDocument->createElementNS(self::NS_RAM, 'ram:'.$name);
        if (null !== $value) {
            $element->appendChild($parent->ownerDocument->createTextNode($value));
        }

        return $parent->appendChild($element);
    }
}
