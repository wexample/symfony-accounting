<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use DateTimeImmutable;
use SimpleXMLElement;
use Wexample\SymfonyAccounting\Class\ElectronicInvoiceImportResult;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyMoney\Helper\MoneyHelper;

/**
 * Reads a received electronic invoice in UN/CEFACT CII, the XML of Factur-X and
 * ZUGFeRD, and hands it to ElectronicInvoiceImporter, which makes it a purchase
 * draft. Type 381 is a credit note, every other type a bill.
 */
class CiiInvoiceReader
{
    private const string SETTLEMENT = '//ram:ApplicableHeaderTradeSettlement';
    private const string SELLER = '//ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty';

    public function __construct(
        private readonly ElectronicInvoiceImporter $importer,
    ) {
    }

    /**
     * @param string $origin Where the XML came from, kept on the draft.
     */
    public function read(
        Ledger $ledger,
        string $xml,
        string $origin = 'cii'
    ): ElectronicInvoiceImportResult {
        return $this->importer->import($ledger, $this->parse($xml, $ledger->getCurrencyCode()), $origin);
    }

    /**
     * The document in the terms ElectronicInvoiceImporter takes.
     */
    public function parse(
        string $xml,
        string $defaultCurrency
    ): array {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if (false === $document || 'CrossIndustryInvoice' !== $document->getName() || ! in_array(CiiInvoiceBuilder::NS_RSM, $document->getNamespaces(), true)) {
            throw new AccountingException('This is not a CII invoice.');
        }

        $this->registerNamespaces($document);
        $currency = $this->text($document, self::SETTLEMENT.'/ram:InvoiceCurrencyCode') ?? $defaultCurrency;
        $iban = $this->text($document, self::SETTLEMENT.'/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID');
        $reference = $this->text($document, self::SETTLEMENT.'/ram:PaymentReference');

        return [
            'number' => $this->text($document, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID'),
            'credit' => '381' === $this->text($document, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode'),
            'issueDate' => (string) $this->date($document, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString'),
            'dueDate' => $this->date($document, self::SETTLEMENT.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString'),
            'note' => $this->text($document, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IncludedNote/ram:Content'),
            'currency' => $currency,
            'buyerReference' => $this->text($document, '//ram:ApplicableHeaderTradeAgreement/ram:BuyerReference')
                ?? $this->text($document, '//ram:ApplicableHeaderTradeAgreement/ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID'),
            'seller' => [
                'name' => $this->text($document, self::SELLER.'/ram:Name'),
                'vatNumber' => $this->text($document, self::SELLER.'/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'),
                'legalIdentifier' => ($id = $this->text($document, self::SELLER.'/ram:SpecifiedLegalOrganization/ram:ID'))
                    ? ['id' => $id, 'scheme' => $this->text($document, self::SELLER.'/ram:SpecifiedLegalOrganization/ram:ID/@schemeID')]
                    : null,
                'addressLines' => array_values(array_filter([
                    $this->text($document, self::SELLER.'/ram:PostalTradeAddress/ram:LineOne'),
                    $this->text($document, self::SELLER.'/ram:PostalTradeAddress/ram:LineTwo'),
                ])),
                'city' => $this->text($document, self::SELLER.'/ram:PostalTradeAddress/ram:CityName'),
                'postCode' => $this->text($document, self::SELLER.'/ram:PostalTradeAddress/ram:PostcodeCode'),
                'country' => $this->text($document, self::SELLER.'/ram:PostalTradeAddress/ram:CountryID'),
            ],
            'payment' => $iban || $reference ? ['reference' => $reference, 'iban' => $iban] : null,
            'allowances' => array_map(
                fn (SimpleXMLElement $node) => ['amount' => $this->allowanceAmount($node, $currency)],
                $document->xpath(self::SETTLEMENT.'/ram:SpecifiedTradeAllowanceCharge') ?: []
            ),
            'totals' => ['total' => $this->text($document, self::SETTLEMENT.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount')],
            'lines' => array_map(
                fn (SimpleXMLElement $line) => $this->line($line, $currency),
                $document->xpath('//rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem') ?: []
            ),
            'embeddedDocument' => null !== $this->text($document, '//ram:AdditionalReferencedDocument/ram:AttachmentBinaryObject'),
        ];
    }

    private function line(
        SimpleXMLElement $line,
        string $currency
    ): array {
        $this->registerNamespaces($line);
        $price = MoneyHelper::fromDecimal((string) ($this->text($line, 'ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount') ?? '0'), $currency);
        $baseQuantity = (float) ($this->text($line, 'ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity') ?? 1) ?: 1.0;
        $discount = array_sum(array_map(
            fn (SimpleXMLElement $node) => MoneyHelper::fromDecimal($this->allowanceAmount($node, $currency), $currency),
            $line->xpath('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeAllowanceCharge') ?: []
        ));

        return [
            'name' => $this->text($line, 'ram:SpecifiedTradeProduct/ram:Name'),
            'description' => $this->text($line, 'ram:SpecifiedTradeProduct/ram:Description'),
            'quantity' => $this->text($line, 'ram:SpecifiedLineTradeDelivery/ram:BilledQuantity'),
            'price' => MoneyHelper::toDecimal((int) round($price / $baseQuantity), $currency),
            'category' => [
                'id' => $this->text($line, 'ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'),
                'rate' => $this->text($line, 'ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent'),
            ],
            'discount' => $discount ? ['amount' => MoneyHelper::toDecimal($discount, $currency), 'percent' => null] : null,
        ];
    }

    /**
     * Signed: an allowance reduces, a charge adds.
     */
    private function allowanceAmount(
        SimpleXMLElement $node,
        string $currency
    ): string {
        $this->registerNamespaces($node);
        $amount = MoneyHelper::fromDecimal((string) $this->text($node, 'ram:ActualAmount'), $currency);

        return MoneyHelper::toDecimal('true' === $this->text($node, 'ram:ChargeIndicator/udt:Indicator') ? -$amount : $amount, $currency);
    }

    /**
     * A CII date (format 102, `YYYYMMDD`) as `Y-m-d`.
     */
    private function date(
        SimpleXMLElement $node,
        string $path
    ): ?string {
        $value = $this->text($node, $path);
        $date = $value ? DateTimeImmutable::createFromFormat('!Ymd', $value) : false;

        return $date ? $date->format('Y-m-d') : null;
    }

    private function registerNamespaces(SimpleXMLElement $node): void
    {
        $node->registerXPathNamespace('rsm', CiiInvoiceBuilder::NS_RSM);
        $node->registerXPathNamespace('ram', CiiInvoiceBuilder::NS_RAM);
        $node->registerXPathNamespace('udt', CiiInvoiceBuilder::NS_UDT);
    }

    private function text(
        SimpleXMLElement $node,
        string $path
    ): ?string {
        $found = $node->xpath($path)[0] ?? null;
        $value = null === $found ? null : trim((string) $found);

        return '' === $value ? null : $value;
    }
}
