<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use DOMDocument;
use DOMElement;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Enum\VatKind;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyGeo\Interface\PostalAddressInterface;
use Wexample\SymfonyMoney\Enum\PriceUnit;
use Wexample\SymfonyMoney\Helper\MoneyHelper;
use Wexample\SymfonyMoney\Helper\RateHelper;

/**
 * The structured electronic invoice: UBL 2.1 following Peppol BIS Billing 3.0
 * (EN 16931), mandatory for B2B invoices in Belgium since 2026 and one of the
 * formats of the French reform. Emitted bills and penalties become Invoice
 * documents (type 380), credit notes CreditNote documents (type 381).
 *
 * Built from the emitted document and its snapshots. Sending it (a Peppol
 * access point) is a remote's job, not this one's.
 */
class UblInvoiceBuilder
{
    public const string NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    public const string NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
    public const string NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    public const string NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /** Peppol electronic address schemes (ICD / EAS) for VAT numbers by country. */
    public const array VAT_SCHEMES = [
        'AT' => '9915', 'BE' => '9925', 'BG' => '9926', 'CY' => '9928', 'CZ' => '9929', 'DE' => '9930',
        'DK' => '0184', 'EE' => '9931', 'ES' => '9920', 'FR' => '9957', 'GR' => '9933', 'HR' => '9934',
        'HU' => '9910', 'IE' => '9935', 'IT' => '0211', 'LT' => '9937', 'LU' => '9938', 'LV' => '9939',
        'MT' => '9940', 'NL' => '9944', 'PL' => '9945', 'PT' => '9946', 'RO' => '9947', 'SE' => '9955',
        'SI' => '9949', 'SK' => '9950',
    ];

    public function build(Invoice $invoice): string
    {
        if (! $invoice->getStatus()->isEmitted() || ! in_array($invoice->getType(), [InvoiceType::Bill, InvoiceType::Penalty, InvoiceType::CreditNote], true)) {
            throw new AccountingException('Only an emitted bill, penalty or credit note becomes an electronic invoice.');
        }

        $credit = $invoice->isCreditNote();
        $currency = $invoice->getCurrencyCode();
        $amount = fn (int $minor) => MoneyHelper::toDecimal($minor, $currency);
        $breakdown = $invoice->calcPriceBreakdown();

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->appendChild($xml->createElementNS($credit ? self::NS_CREDIT_NOTE : self::NS_INVOICE, $credit ? 'CreditNote' : 'Invoice'));
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC);

        $this->cbc($root, 'CustomizationID', 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0');
        $this->cbc($root, 'ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
        $this->cbc($root, 'ID', (string) $invoice->getNumber());
        $this->cbc($root, 'IssueDate', $invoice->getDateInvoice()->format('Y-m-d'));

        if (! $credit) {
            $this->cbc($root, 'DueDate', $invoice->calcDateDue()->format('Y-m-d'));
        }

        $this->cbc($root, $credit ? 'CreditNoteTypeCode' : 'InvoiceTypeCode', $credit ? '381' : '380');

        if ($invoice->getNote()) {
            $this->cbc($root, 'Note', $invoice->getNote());
        }

        $this->cbc($root, 'DocumentCurrencyCode', $currency);
        $this->cbc($root, 'BuyerReference', $invoice->getTitle() ?? (string) $invoice->getNumber());

        if ($invoice->getPeriodStart() && $invoice->getPeriodEnd()) {
            $period = $this->cac($root, 'InvoicePeriod');
            $this->cbc($period, 'StartDate', $invoice->getPeriodStart()->format('Y-m-d'));
            $this->cbc($period, 'EndDate', $invoice->getPeriodEnd()->format('Y-m-d'));
        }

        $this->party($this->cac($root, 'AccountingSupplierParty'), $invoice->getIssuerSnapshot() ?? [], $invoice->getLedger());
        $this->party($this->cac($root, 'AccountingCustomerParty'), $invoice->getPartySnapshot() ?? [], $invoice->getParty());

        $bank = $invoice->getIssuerSnapshot()['bank'] ?? null;
        if (! $credit && ($bank['iban'] ?? null)) {
            $means = $this->cac($root, 'PaymentMeans');
            $this->cbc($means, 'PaymentMeansCode', '30');
            $this->cbc($means, 'PaymentID', (string) ($invoice->getPaymentReference() ?? $invoice->getNumber()));
            $account = $this->cac($means, 'PayeeFinancialAccount');
            $this->cbc($account, 'ID', $bank['iban']);
            if ($bank['bic'] ?? null) {
                $this->cbc($this->cac($account, 'FinancialInstitutionBranch'), 'ID', $bank['bic']);
            }
        }

        if (! $credit) {
            $this->cbc($this->cac($root, 'PaymentTerms'), 'Note', sprintf('%d days', $invoice->getPaymentTermDays()));
        }

        // Document discount, one allowance per VAT category it reduces.
        foreach ($breakdown->vatLines as $rate => $vatLine) {
            $gross = $this->categoryGross($invoice, $rate);
            $discount = $gross - $vatLine->base;

            if ($discount > 0) {
                $allowance = $this->cac($root, 'AllowanceCharge');
                $this->cbc($allowance, 'ChargeIndicator', 'false');
                $this->cbc($allowance, 'AllowanceChargeReason', 'Discount');
                $this->money($this->cbc($allowance, 'Amount', $amount($discount)), $currency);
                $this->taxCategory($allowance, 'TaxCategory', $this->categoryOf($invoice, $rate), $rate);
            }
        }

        $taxTotal = $this->cac($root, 'TaxTotal');
        $this->money($this->cbc($taxTotal, 'TaxAmount', $amount($breakdown->getVat())), $currency);

        foreach ($breakdown->vatLines as $rate => $vatLine) {
            $subtotal = $this->cac($taxTotal, 'TaxSubtotal');
            $this->money($this->cbc($subtotal, 'TaxableAmount', $amount($vatLine->base)), $currency);
            $this->money($this->cbc($subtotal, 'TaxAmount', $amount($vatLine->vat)), $currency);
            $this->taxCategory($subtotal, 'TaxCategory', $this->categoryOf($invoice, $rate), $rate, true);
        }

        $lineTotal = array_sum(array_map(fn (InvoiceItem $item) => $item->calcPriceNet(), $invoice->getItems()->toArray()));
        $totals = $this->cac($root, 'LegalMonetaryTotal');
        $this->money($this->cbc($totals, 'LineExtensionAmount', $amount($lineTotal)), $currency);
        $this->money($this->cbc($totals, 'TaxExclusiveAmount', $amount($breakdown->getNet())), $currency);
        $this->money($this->cbc($totals, 'TaxInclusiveAmount', $amount($breakdown->getTotal())), $currency);

        if ($breakdown->discount > 0) {
            $this->money($this->cbc($totals, 'AllowanceTotalAmount', $amount($lineTotal - $breakdown->getNet())), $currency);
        }

        $paid = $invoice->calcPaidAmount();
        if ($paid > 0) {
            $this->money($this->cbc($totals, 'PrepaidAmount', $amount($paid)), $currency);
        }

        $this->money($this->cbc($totals, 'PayableAmount', $amount($breakdown->getTotal() - $paid)), $currency);

        foreach ($invoice->getItems() as $index => $item) {
            $this->line($root, $item, $index + 1, $credit, $currency);
        }

        return $xml->saveXML();
    }

    /**
     * EN 16931 VAT category of the items at a rate.
     */
    public function categoryOf(
        Invoice $invoice,
        int $rate
    ): array {
        foreach ($invoice->getItems() as $item) {
            if ($item->getPriceVat() === $rate) {
                return $this->categoryOfItem($item);
            }
        }

        return ['id' => $rate > 0 ? 'S' : 'Z', 'reason' => null];
    }

    /**
     * @return array{id: string, reason: ?string, code?: string}
     */
    public function categoryOfItem(InvoiceItem $item): array
    {
        return match ($item->getVatKind()) {
            VatKind::IntraEuServices, VatKind::ReverseCharge, VatKind::IntraEuAcquisition => ['id' => 'AE', 'reason' => 'Reverse charge', 'code' => 'VATEX-EU-AE'],
            VatKind::IntraEuGoods => ['id' => 'K', 'reason' => 'Intra-Community supply', 'code' => 'VATEX-EU-IC'],
            VatKind::Export => ['id' => 'G', 'reason' => 'Export outside the EU', 'code' => 'VATEX-EU-G'],
            VatKind::Exempt => ['id' => 'E', 'reason' => 'Exempt', 'code' => null],
            VatKind::Franchise => ['id' => 'E', 'reason' => 'Small business exemption', 'code' => null],
            VatKind::OutOfScope => ['id' => 'O', 'reason' => 'Not subject to VAT', 'code' => 'VATEX-EU-O'],
            default => ['id' => $item->getPriceVat() > 0 ? 'S' : 'Z', 'reason' => null],
        };
    }

    private function line(
        DOMElement $root,
        InvoiceItem $item,
        int $number,
        bool $credit,
        string $currency
    ): void {
        $amount = fn (int $minor) => MoneyHelper::toDecimal($minor, $currency);
        $line = $this->cac($root, $credit ? 'CreditNoteLine' : 'InvoiceLine');
        $this->cbc($line, 'ID', (string) $number);

        $quantity = $this->cbc($line, $credit ? 'CreditedQuantity' : 'InvoicedQuantity', $this->quantity((int) $item->getQuantity()));
        $quantity->setAttribute('unitCode', match ($item->getUnit()) {
            'hour' => 'HUR',
            'day' => 'DAY',
            'month' => 'MON',
            default => 'C62',
        });

        $this->money($this->cbc($line, 'LineExtensionAmount', $amount($item->calcPriceNet())), $currency);

        $breakdown = $item->calcPriceBreakdown();
        if ($breakdown->discount > 0) {
            $allowance = $this->cac($line, 'AllowanceCharge');
            $this->cbc($allowance, 'ChargeIndicator', 'false');
            $this->cbc($allowance, 'AllowanceChargeReason', 'Discount');
            if (PriceUnit::Percent === $item->getPriceDiscountUnit()) {
                $this->cbc($allowance, 'MultiplierFactorNumeric', number_format($item->getPriceDiscount() / 100, 2, '.', ''));
            }
            $this->money($this->cbc($allowance, 'Amount', $amount($breakdown->discount)), $currency);
        }

        $product = $this->cac($line, 'Item');
        if ($item->getDescription()) {
            $this->cbc($product, 'Description', $item->getDescription());
        }
        $this->cbc($product, 'Name', $item->getTitle());
        $category = $this->categoryOfItem($item);
        $this->taxCategory($product, 'ClassifiedTaxCategory', $category, $item->getPriceVat());

        $price = $this->cac($line, 'Price');
        $this->money($this->cbc($price, 'PriceAmount', $amount((int) $item->getPriceRaw())), $currency);
    }

    private function party(
        DOMElement $parent,
        array $identity,
        ?PostalAddressInterface $address
    ): void {
        $party = $this->cac($parent, 'Party');
        $country = (string) $address?->getCountry()?->getIsoAlpha2Code();
        [$scheme, $endpoint] = $this->endpoint($identity, $country);

        if ($endpoint) {
            $this->cbc($party, 'EndpointID', $endpoint)->setAttribute('schemeID', $scheme);
        }

        $this->cbc($this->cac($party, 'PartyName'), 'Name', (string) ($identity['name'] ?? ''));

        $postal = $this->cac($party, 'PostalAddress');
        $lines = array_values(array_filter(preg_split('/\R/', (string) $address?->getPostalAddress())));
        if ($lines[0] ?? null) {
            $this->cbc($postal, 'StreetName', $lines[0]);
        }
        if ($lines[1] ?? null) {
            $this->cbc($postal, 'AdditionalStreetName', $lines[1]);
        }
        if ($address?->getCity()) {
            $this->cbc($postal, 'CityName', $address->getCity());
        }
        if ($address?->getPostCode()) {
            $this->cbc($postal, 'PostalZone', $address->getPostCode());
        }
        if ('' !== $country) {
            $this->cbc($this->cac($postal, 'Country'), 'IdentificationCode', $country);
        }

        if ($identity['vatNumber'] ?? null) {
            $tax = $this->cac($party, 'PartyTaxScheme');
            $this->cbc($tax, 'CompanyID', $identity['vatNumber']);
            $this->cbc($this->cac($tax, 'TaxScheme'), 'ID', 'VAT');
        }

        $legal = $this->cac($party, 'PartyLegalEntity');
        $this->cbc($legal, 'RegistrationName', (string) ($identity['name'] ?? ''));
        if ($identity['legalIdentifier'] ?? null) {
            $this->cbc($legal, 'CompanyID', preg_replace('/[^0-9A-Z]/', '', strtoupper($identity['legalIdentifier'])));
        }
    }

    /**
     * The Peppol address: the Belgian enterprise number (0208), the French SIRET
     * (0009), otherwise the VAT number with its country's scheme.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function endpoint(
        array $identity,
        string $country
    ): array {
        $identifier = preg_replace('/\D/', '', (string) ($identity['legalIdentifier'] ?? ''));

        if ('BE' === $country && 10 === strlen($identifier)) {
            return ['0208', $identifier];
        }

        if ('FR' === $country && 14 === strlen($identifier)) {
            return ['0009', $identifier];
        }

        $vat = preg_replace('/[^0-9A-Z]/', '', strtoupper((string) ($identity['vatNumber'] ?? '')));

        if ($vat && isset(self::VAT_SCHEMES[$country])) {
            return [self::VAT_SCHEMES[$country], $vat];
        }

        return [null, null];
    }

    private function taxCategory(
        DOMElement $parent,
        string $name,
        array $category,
        int $rate,
        bool $withReason = false
    ): void {
        $element = $this->cac($parent, $name);
        $this->cbc($element, 'ID', $category['id']);
        $this->cbc($element, 'Percent', number_format(in_array($category['id'], ['S', 'Z'], true) ? $rate / 100 : 0, 2, '.', ''));

        if ($withReason && ($category['code'] ?? null)) {
            $this->cbc($element, 'TaxExemptionReasonCode', $category['code']);
        } elseif ($withReason && $category['reason']) {
            $this->cbc($element, 'TaxExemptionReason', $category['reason']);
        }

        $this->cbc($this->cac($element, 'TaxScheme'), 'ID', 'VAT');
    }

    private function categoryGross(
        Invoice $invoice,
        int $rate
    ): int {
        $gross = 0;

        foreach ($invoice->getItems() as $item) {
            if ($item->getPriceVat() === $rate) {
                $breakdown = $item->calcPriceBreakdown();
                $gross += $breakdown->isOverridden()
                    ? RateHelper::extractBase($breakdown->overridden, $rate)
                    : $breakdown->getNet();
            }
        }

        return $gross;
    }

    private function quantity(int $scaled): string
    {
        return rtrim(rtrim(number_format($scaled / InvoiceItem::QUANTITY_SCALE, 2, '.', ''), '0'), '.');
    }

    private function money(
        DOMElement $element,
        string $currency
    ): DOMElement {
        $element->setAttribute('currencyID', $currency);

        return $element;
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
