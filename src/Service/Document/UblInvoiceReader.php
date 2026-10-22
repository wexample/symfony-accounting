<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use SimpleXMLElement;
use Wexample\SymfonyAccounting\Class\ElectronicInvoiceImportResult;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyMoney\Helper\MoneyHelper;

/**
 * Reads a received electronic invoice in UBL 2.1 (Peppol BIS Billing 3.0) and
 * hands it to ElectronicInvoiceImporter, which makes it a purchase draft.
 */
class UblInvoiceReader
{
    private const string PARTY = '//cac:AccountingSupplierParty/cac:Party';

    public function __construct(
        private readonly ElectronicInvoiceImporter $importer,
    ) {
    }

    public function read(
        Ledger $ledger,
        string $xml
    ): ElectronicInvoiceImportResult {
        return $this->importer->import($ledger, $this->parse($xml, $ledger->getCurrencyCode()), 'peppol');
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

        if (false === $document || ! in_array($document->getName(), ['Invoice', 'CreditNote'], true)) {
            throw new AccountingException('This is not a UBL invoice or credit note.');
        }

        $credit = 'CreditNote' === $document->getName();
        $this->registerNamespaces($document);
        $currency = $this->text($document, '/*/cbc:DocumentCurrencyCode') ?? $defaultCurrency;
        $lineTag = $credit ? 'CreditNoteLine' : 'InvoiceLine';
        $iban = $this->text($document, '//cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID');
        $reference = $this->text($document, '//cac:PaymentMeans/cbc:PaymentID');

        return [
            'number' => $this->text($document, '/*/cbc:ID'),
            'credit' => $credit,
            'issueDate' => (string) $this->text($document, '/*/cbc:IssueDate'),
            'dueDate' => $this->text($document, '/*/cbc:DueDate'),
            'note' => $this->text($document, '/*/cbc:Note'),
            'currency' => $currency,
            'buyerReference' => $this->text($document, '/*/cbc:BuyerReference') ?? $this->text($document, '//cac:OrderReference/cbc:ID'),
            'seller' => [
                'name' => $this->text($document, self::PARTY.'/cac:PartyLegalEntity/cbc:RegistrationName')
                    ?? $this->text($document, self::PARTY.'/cac:PartyName/cbc:Name'),
                'vatNumber' => $this->text($document, self::PARTY.'/cac:PartyTaxScheme/cbc:CompanyID'),
                'legalIdentifier' => ($id = $this->text($document, self::PARTY.'/cac:PartyLegalEntity/cbc:CompanyID')) ? ['id' => $id, 'scheme' => null] : null,
                'addressLines' => array_values(array_filter([
                    $this->text($document, self::PARTY.'/cac:PostalAddress/cbc:StreetName'),
                    $this->text($document, self::PARTY.'/cac:PostalAddress/cbc:AdditionalStreetName'),
                ])),
                'city' => $this->text($document, self::PARTY.'/cac:PostalAddress/cbc:CityName'),
                'postCode' => $this->text($document, self::PARTY.'/cac:PostalAddress/cbc:PostalZone'),
                'country' => $this->text($document, self::PARTY.'/cac:PostalAddress/cac:Country/cbc:IdentificationCode'),
            ],
            'payment' => $iban || $reference ? ['reference' => $reference, 'iban' => $iban] : null,
            'allowances' => array_map(fn (SimpleXMLElement $node) => ['amount' => $this->allowanceAmount($node, $currency)], $document->xpath('/*/cac:AllowanceCharge') ?: []),
            'totals' => ['total' => $this->text($document, '//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount')],
            'lines' => array_map(
                fn (SimpleXMLElement $line) => $this->line($line, $credit ? 'CreditedQuantity' : 'InvoicedQuantity', $currency),
                $document->xpath('/*/cac:'.$lineTag) ?: []
            ),
            'embeddedDocument' => null !== $this->text($document, '//cac:AdditionalDocumentReference/cac:Attachment/cbc:EmbeddedDocumentBinaryObject'),
        ];
    }

    private function line(
        SimpleXMLElement $line,
        string $quantityTag,
        string $currency
    ): array {
        $this->registerNamespaces($line);
        $price = MoneyHelper::fromDecimal((string) ($this->text($line, 'cac:Price/cbc:PriceAmount') ?? '0'), $currency);
        $baseQuantity = (float) ($this->text($line, 'cac:Price/cbc:BaseQuantity') ?? 1) ?: 1.0;
        $discount = array_sum(array_map(
            fn (SimpleXMLElement $node) => MoneyHelper::fromDecimal($this->allowanceAmount($node, $currency), $currency),
            $line->xpath('cac:AllowanceCharge') ?: []
        ));

        return [
            'name' => $this->text($line, 'cac:Item/cbc:Name'),
            'description' => $this->text($line, 'cac:Item/cbc:Description'),
            'quantity' => $this->text($line, 'cbc:'.$quantityTag),
            'price' => MoneyHelper::toDecimal((int) round($price / $baseQuantity), $currency),
            'category' => [
                'id' => $this->text($line, 'cac:Item/cac:ClassifiedTaxCategory/cbc:ID'),
                'rate' => $this->text($line, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent'),
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
        $children = $node->children(UblInvoiceBuilder::NS_CBC);
        $amount = MoneyHelper::fromDecimal((string) $children->Amount, $currency);

        return MoneyHelper::toDecimal('true' === (string) $children->ChargeIndicator ? -$amount : $amount, $currency);
    }

    private function registerNamespaces(SimpleXMLElement $node): void
    {
        $node->registerXPathNamespace('cbc', UblInvoiceBuilder::NS_CBC);
        $node->registerXPathNamespace('cac', UblInvoiceBuilder::NS_CAC);
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
