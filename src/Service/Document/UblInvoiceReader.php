<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use DateTimeImmutable;
use SimpleXMLElement;
use Wexample\SymfonyAccounting\Class\UblImportResult;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Ledger\PartyService;
use Wexample\SymfonyMoney\Enum\PriceUnit;
use Wexample\SymfonyMoney\Helper\MoneyHelper;

/**
 * Turns a received electronic invoice (UBL 2.1, Peppol BIS Billing 3.0) into a
 * purchase draft: supplier found by VAT number (or created, with its IBAN),
 * lines, discounts, payment reference. The draft is checked against the total
 * the supplier stated; a person records it (InvoiceEmissionService).
 */
class UblInvoiceReader
{
    public function __construct(
        private readonly InvoiceFactory $invoiceFactory,
        private readonly PartyService $partyService,
        private readonly PartyRepository $partyRepository,
    ) {
    }

    public function read(
        Ledger $ledger,
        string $xml
    ): UblImportResult {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if (false === $document || ! in_array($document->getName(), ['Invoice', 'CreditNote'], true)) {
            throw new AccountingException('This is not a UBL invoice or credit note.');
        }

        $credit = 'CreditNote' === $document->getName();
        $document->registerXPathNamespace('cbc', UblInvoiceBuilder::NS_CBC);
        $document->registerXPathNamespace('cac', UblInvoiceBuilder::NS_CAC);
        $currency = $this->text($document, '/*/cbc:DocumentCurrencyCode') ?? $ledger->getCurrencyCode();
        $warnings = [];

        if ($currency !== $ledger->getCurrencyCode()) {
            $warnings[] = sprintf('The invoice is in %s, the ledger in %s.', $currency, $ledger->getCurrencyCode());
        }

        [$supplier, $created] = $this->findOrCreateSupplier($ledger, $document);

        $invoice = $this->invoiceFactory->create(
            $ledger,
            $credit ? InvoiceType::CreditNote : InvoiceType::Bill,
            InvoiceDirection::Purchase,
            $supplier,
            new DateTimeImmutable((string) $this->text($document, '/*/cbc:IssueDate')),
            $this->text($document, '/*/cbc:BuyerReference') ?? $this->text($document, '//cac:OrderReference/cbc:ID'),
        )
            ->setNumber($this->text($document, '/*/cbc:ID'))
            ->setNote($this->text($document, '/*/cbc:Note'))
            ->setPaymentReference($this->text($document, '//cac:PaymentMeans/cbc:PaymentID'))
            ->setOrigin('peppol');

        if ($due = $this->text($document, '/*/cbc:DueDate')) {
            $invoice->setDateDue(new DateTimeImmutable($due));
        }

        $lineTag = $credit ? 'CreditNoteLine' : 'InvoiceLine';
        $quantityTag = $credit ? 'CreditedQuantity' : 'InvoicedQuantity';

        foreach ($document->xpath('/*/cac:'.$lineTag) ?: [] as $line) {
            $this->addLine($invoice, $line, $quantityTag, $currency);
        }

        $discount = 0;
        foreach ($document->xpath('/*/cac:AllowanceCharge') ?: [] as $allowanceCharge) {
            $amount = MoneyHelper::fromDecimal((string) $this->child($allowanceCharge, 'cbc', 'Amount'), $currency);
            $discount += 'true' === (string) $this->child($allowanceCharge, 'cbc', 'ChargeIndicator') ? -$amount : $amount;
        }

        if (0 !== $discount) {
            $invoice->setPriceDiscount($discount, PriceUnit::Money);
        }

        $stated = MoneyHelper::fromDecimal((string) $this->text($document, '//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'), $currency);

        if ($stated !== $invoice->calcPriceFinal()) {
            $warnings[] = sprintf('The supplier states a total of %d, the lines give %d.', $stated, $invoice->calcPriceFinal());
        }

        if ($attachment = $this->text($document, '//cac:AdditionalDocumentReference/cac:Attachment/cbc:EmbeddedDocumentBinaryObject')) {
            $invoice->setMetadata($invoice->getMetadata() + ['embedded_document' => true]);
        }

        return new UblImportResult($invoice, $created, $warnings);
    }

    private function addLine(
        Invoice $invoice,
        SimpleXMLElement $line,
        string $quantityTag,
        string $currency
    ): void {
        $line->registerXPathNamespace('cbc', UblInvoiceBuilder::NS_CBC);
        $line->registerXPathNamespace('cac', UblInvoiceBuilder::NS_CAC);
        $quantity = (float) ($this->text($line, 'cbc:'.$quantityTag) ?? 1);
        $unitPrice = MoneyHelper::fromDecimal((string) ($this->text($line, 'cac:Price/cbc:PriceAmount') ?? '0'), $currency);
        $baseQuantity = (float) ($this->text($line, 'cac:Price/cbc:BaseQuantity') ?? 1) ?: 1.0;
        $percent = (float) ($this->text($line, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent') ?? 0);
        $category = $this->text($line, 'cac:Item/cac:ClassifiedTaxCategory/cbc:ID');

        $item = $this->invoiceFactory->addItem(
            $invoice,
            (string) ($this->text($line, 'cac:Item/cbc:Name') ?? 'Line'),
            (int) round($unitPrice / $baseQuantity),
            (int) round($quantity * InvoiceItem::QUANTITY_SCALE),
            in_array($category, ['S', 'Z'], true) ? (int) round($percent * 100) : 0,
        );
        $item->setDescription($this->text($line, 'cac:Item/cbc:Description'));

        $discount = 0;
        foreach ($line->xpath('cac:AllowanceCharge') ?: [] as $allowanceCharge) {
            $amount = MoneyHelper::fromDecimal((string) $this->child($allowanceCharge, 'cbc', 'Amount'), $currency);
            $discount += 'true' === (string) $this->child($allowanceCharge, 'cbc', 'ChargeIndicator') ? -$amount : $amount;
        }

        if (0 !== $discount) {
            $item->setPriceDiscount($discount, PriceUnit::Money);
        }
    }

    /**
     * @return array{0: Party, 1: bool}
     */
    private function findOrCreateSupplier(
        Ledger $ledger,
        SimpleXMLElement $document
    ): array {
        $party = '//cac:AccountingSupplierParty/cac:Party';
        $vatNumber = $this->text($document, $party.'/cac:PartyTaxScheme/cbc:CompanyID');

        if ($vatNumber) {
            $existing = $this->partyRepository->findOneBy(['ledger' => $ledger, 'vatNumber' => strtoupper(preg_replace('/[\s.\-]/', '', $vatNumber))]);

            if ($existing) {
                $existing->setSupplier(true);
                $this->partyService->assignCodes($existing);

                return [$existing, false];
            }
        }

        $name = $this->text($document, $party.'/cac:PartyLegalEntity/cbc:RegistrationName')
            ?? $this->text($document, $party.'/cac:PartyName/cbc:Name')
            ?? 'Supplier';
        $country = $this->text($document, $party.'/cac:PostalAddress/cac:Country/cbc:IdentificationCode');

        $supplier = $this->partyService->create($ledger, $name, customer: false, supplier: true, countryCode: $country);
        $supplier
            ->setVatNumber($vatNumber)
            ->setLegalIdentifier($this->text($document, $party.'/cac:PartyLegalEntity/cbc:CompanyID'))
            ->setPostalAddress(trim(($this->text($document, $party.'/cac:PostalAddress/cbc:StreetName') ?? '')."\n".($this->text($document, $party.'/cac:PostalAddress/cbc:AdditionalStreetName') ?? '')) ?: null)
            ->setPostCode($this->text($document, $party.'/cac:PostalAddress/cbc:PostalZone'))
            ->setCity($this->text($document, $party.'/cac:PostalAddress/cbc:CityName'))
            ->setIban($this->text($document, '//cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID'));

        return [$supplier, true];
    }

    private function text(
        SimpleXMLElement $node,
        string $path
    ): ?string {
        $found = $node->xpath($path)[0] ?? null;
        $value = null === $found ? null : trim((string) $found);

        return '' === $value ? null : $value;
    }

    private function child(
        SimpleXMLElement $node,
        string $prefix,
        string $name
    ): ?SimpleXMLElement {
        $children = $node->children($prefix === 'cbc' ? UblInvoiceBuilder::NS_CBC : UblInvoiceBuilder::NS_CAC);

        return isset($children->{$name}) ? $children->{$name} : null;
    }
}
