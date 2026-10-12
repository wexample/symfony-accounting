<?php

namespace Wexample\SymfonyAccounting\Service\Document;

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
 * An emitted document in the terms of EN 16931, the European semantic model of
 * an invoice: what UblInvoiceBuilder (UBL, Peppol) and CiiInvoiceBuilder (CII,
 * Factur-X) both write, each in its own syntax. Read from the emitted document
 * and its snapshots; amounts are decimal strings in the document's currency.
 *
 * A VAT category is `{id, rate, reason, code}`: `rate` is a percentage string,
 * null for category O (not subject to VAT), which states none.
 */
class ElectronicInvoiceDataBuilder
{
    /** Peppol electronic address schemes (ICD / EAS) for VAT numbers by country. */
    public const array VAT_SCHEMES = [
        'AT' => '9915', 'BE' => '9925', 'BG' => '9926', 'CY' => '9928', 'CZ' => '9929', 'DE' => '9930',
        'DK' => '0184', 'EE' => '9931', 'ES' => '9920', 'FR' => '9957', 'GR' => '9933', 'HR' => '9934',
        'HU' => '9910', 'IE' => '9935', 'IT' => '0211', 'LT' => '9937', 'LU' => '9938', 'LV' => '9939',
        'MT' => '9940', 'NL' => '9944', 'PL' => '9945', 'PT' => '9946', 'RO' => '9947', 'SE' => '9955',
        'SI' => '9949', 'SK' => '9950',
    ];

    public function supports(Invoice $invoice): bool
    {
        return $invoice->getStatus()->isEmitted()
            && in_array($invoice->getType(), [InvoiceType::Bill, InvoiceType::Penalty, InvoiceType::CreditNote], true);
    }

    public function build(Invoice $invoice): array
    {
        if (! $this->supports($invoice)) {
            throw new AccountingException('Only an emitted bill, penalty or credit note becomes an electronic invoice.');
        }

        $credit = $invoice->isCreditNote();
        $currency = $invoice->getCurrencyCode();
        $amount = fn (int $minor) => MoneyHelper::toDecimal($minor, $currency);
        $breakdown = $invoice->calcPriceBreakdown();
        $bank = $invoice->getIssuerSnapshot()['bank'] ?? null;

        // Document discount, one allowance per VAT category it reduces.
        $allowances = [];
        $vat = [];
        foreach ($breakdown->vatLines as $rate => $vatLine) {
            $category = $this->categoryOf($invoice, $rate);
            $discount = $this->categoryGross($invoice, $rate) - $vatLine->base;

            if ($discount > 0) {
                $allowances[] = ['amount' => $amount($discount), 'category' => $category];
            }

            $vat[] = ['base' => $amount($vatLine->base), 'amount' => $amount($vatLine->vat), 'category' => $category];
        }

        $items = array_values($invoice->getItems()->toArray());
        $lineTotal = array_sum(array_map(fn (InvoiceItem $item) => $item->calcPriceNet(), $items));
        $paid = $invoice->calcPaidAmount();

        return [
            'number' => (string) $invoice->getNumber(),
            'credit' => $credit,
            'typeCode' => $credit ? '381' : '380',
            'issueDate' => $invoice->getDateInvoice(),
            'dueDate' => $credit ? null : $invoice->calcDateDue(),
            'note' => $invoice->getNote() ?: null,
            'currency' => $currency,
            'buyerReference' => $invoice->getTitle() ?? (string) $invoice->getNumber(),
            'period' => $invoice->getPeriodStart() && $invoice->getPeriodEnd()
                ? ['start' => $invoice->getPeriodStart(), 'end' => $invoice->getPeriodEnd()]
                : null,
            'seller' => $this->party($invoice->getIssuerSnapshot() ?? [], $invoice->getLedger()),
            'buyer' => $this->party($invoice->getPartySnapshot() ?? [], $invoice->getParty()),
            'payment' => ! $credit && ($bank['iban'] ?? null) ? [
                'reference' => (string) ($invoice->getPaymentReference() ?? $invoice->getNumber()),
                'iban' => $bank['iban'],
                'bic' => $bank['bic'] ?? null,
            ] : null,
            'paymentTerms' => $credit ? null : sprintf('%d days', $invoice->getPaymentTermDays()),
            'allowances' => $allowances,
            'vat' => $vat,
            'totals' => [
                'lines' => $amount($lineTotal),
                'allowances' => $breakdown->discount > 0 ? $amount($lineTotal - $breakdown->getNet()) : null,
                'net' => $amount($breakdown->getNet()),
                'vat' => $amount($breakdown->getVat()),
                'total' => $amount($breakdown->getTotal()),
                'paid' => $paid > 0 ? $amount($paid) : null,
                'payable' => $amount($breakdown->getTotal() - $paid),
            ],
            'lines' => array_map(fn (InvoiceItem $item, int $index) => $this->line($item, $index + 1, $amount), $items, array_keys($items)),
        ];
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

        return $this->category($rate > 0 ? 'S' : 'Z', $rate);
    }

    /**
     * @return array{id: string, rate: ?string, reason: ?string, code: ?string}
     */
    public function categoryOfItem(InvoiceItem $item): array
    {
        $rate = (int) $item->getPriceVat();

        return match ($item->getVatKind()) {
            VatKind::IntraEuServices, VatKind::ReverseCharge, VatKind::IntraEuAcquisition => $this->category('AE', $rate, 'Reverse charge', 'VATEX-EU-AE'),
            VatKind::IntraEuGoods => $this->category('K', $rate, 'Intra-Community supply', 'VATEX-EU-IC'),
            VatKind::Export => $this->category('G', $rate, 'Export outside the EU', 'VATEX-EU-G'),
            VatKind::Exempt => $this->category('E', $rate, 'Exempt'),
            VatKind::Franchise => $this->category('E', $rate, 'Small business exemption'),
            VatKind::OutOfScope => $this->category('O', $rate, 'Not subject to VAT', 'VATEX-EU-O'),
            default => $this->category($rate > 0 ? 'S' : 'Z', $rate),
        };
    }

    private function category(
        string $id,
        int $rate,
        ?string $reason = null,
        ?string $code = null
    ): array {
        return [
            'id' => $id,
            'rate' => match ($id) {
                'S', 'Z' => number_format($rate / 100, 2, '.', ''),
                'O' => null,
                default => '0.00',
            },
            'reason' => $reason,
            'code' => $code,
        ];
    }

    private function line(
        InvoiceItem $item,
        int $number,
        callable $amount
    ): array {
        $breakdown = $item->calcPriceBreakdown();

        return [
            'id' => (string) $number,
            'quantity' => $this->quantity((int) $item->getQuantity()),
            'unitCode' => match ($item->getUnit()) {
                'hour' => 'HUR',
                'day' => 'DAY',
                'month' => 'MON',
                default => 'C62',
            },
            'net' => $amount($item->calcPriceNet()),
            'discount' => $breakdown->discount > 0 ? [
                'amount' => $amount($breakdown->discount),
                'percent' => PriceUnit::Percent === $item->getPriceDiscountUnit()
                    ? number_format($item->getPriceDiscount() / 100, 2, '.', '')
                    : null,
            ] : null,
            'name' => $item->getTitle(),
            'description' => $item->getDescription() ?: null,
            'category' => $this->categoryOfItem($item),
            'price' => $amount((int) $item->getPriceRaw()),
        ];
    }

    private function party(
        array $identity,
        ?PostalAddressInterface $address
    ): array {
        $country = (string) $address?->getCountry()?->getIsoAlpha2Code();
        $identifier = preg_replace('/[^0-9A-Z]/', '', strtoupper((string) ($identity['legalIdentifier'] ?? '')));
        $lines = array_values(array_filter(preg_split('/\R/', (string) $address?->getPostalAddress())));

        return [
            'name' => (string) ($identity['name'] ?? ''),
            'endpoint' => $this->endpoint($identity, $country),
            'addressLines' => array_slice($lines, 0, 2),
            'city' => $address?->getCity() ?: null,
            'postCode' => $address?->getPostCode() ?: null,
            'country' => $country ?: null,
            'vatNumber' => $identity['vatNumber'] ?? null,
            'legalIdentifier' => $identifier ? ['id' => $identifier, 'scheme' => $this->legalScheme($identifier, $country)] : null,
        ];
    }

    /**
     * The electronic address: the Belgian enterprise number (0208), the French
     * SIRET (0009), otherwise the VAT number with its country's scheme.
     *
     * @return array{scheme: string, id: string}|null
     */
    private function endpoint(
        array $identity,
        string $country
    ): ?array {
        $identifier = preg_replace('/\D/', '', (string) ($identity['legalIdentifier'] ?? ''));

        if ('BE' === $country && 10 === strlen($identifier)) {
            return ['scheme' => '0208', 'id' => $identifier];
        }

        if ('FR' === $country && 14 === strlen($identifier)) {
            return ['scheme' => '0009', 'id' => $identifier];
        }

        $vat = preg_replace('/[^0-9A-Z]/', '', strtoupper((string) ($identity['vatNumber'] ?? '')));

        if ($vat && isset(self::VAT_SCHEMES[$country])) {
            return ['scheme' => self::VAT_SCHEMES[$country], 'id' => $vat];
        }

        return null;
    }

    /**
     * The registry a legal identifier belongs to (ISO 6523 ICD): SIREN 0002,
     * SIRET 0009, Belgian enterprise number 0208.
     */
    private function legalScheme(
        string $identifier,
        string $country
    ): ?string {
        return match (true) {
            'FR' === $country && 9 === strlen($identifier) => '0002',
            'FR' === $country && 14 === strlen($identifier) => '0009',
            'BE' === $country && 10 === strlen($identifier) => '0208',
            default => null,
        };
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
}
