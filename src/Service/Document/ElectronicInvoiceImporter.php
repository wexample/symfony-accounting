<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\ElectronicInvoiceImportResult;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Ledger\PartyService;
use Wexample\SymfonyGeo\Repository\AddressRepository;
use Wexample\SymfonyGeo\Repository\CountryRepository;
use Wexample\SymfonyMoney\Enum\PriceUnit;
use Wexample\SymfonyMoney\Helper\MoneyHelper;
use Wexample\SymfonyOrganization\Service\OrganizationService;

/**
 * Turns a received electronic invoice, read by UblInvoiceReader or CiiInvoiceReader
 * into the terms of ElectronicInvoiceDataBuilder, into a purchase draft: supplier
 * found by VAT number (or created, with its IBAN), lines, discounts, payment
 * reference. The draft is checked against the total the supplier stated; a person
 * records it (InvoiceEmissionService).
 *
 * Read as received, the model is looser than the one written: dates are `Y-m-d`
 * strings, a missing value is null, `allowances` and a line's `discount` are signed
 * (a charge is a negative allowance), and `price` is per unit already.
 */
class ElectronicInvoiceImporter
{
    public function __construct(
        private readonly InvoiceFactory $invoiceFactory,
        private readonly PartyService $partyService,
        private readonly PartyRepository $partyRepository,
        private readonly CountryRepository $countryRepository,
        private readonly AddressRepository $addressRepository,
        private readonly OrganizationService $organizationService,
    ) {
    }

    /**
     * @param string $origin Where the document came from (`peppol`, `factur-x`), kept on the draft.
     */
    public function import(
        Ledger $ledger,
        array $data,
        string $origin
    ): ElectronicInvoiceImportResult {
        $currency = $data['currency'] ?? $ledger->getCurrencyCode();
        $money = fn (?string $amount) => MoneyHelper::fromDecimal($amount ?? '0', $currency);
        $warnings = [];

        [$supplier, $created] = $this->findOrCreateSupplier($ledger, $data['seller'], $data['payment']['iban'] ?? null);

        $invoice = $this->invoiceFactory->create(
            $ledger,
            $data['credit'] ? InvoiceType::CreditNote : InvoiceType::Bill,
            InvoiceDirection::Purchase,
            $supplier,
            new DateTimeImmutable($data['issueDate']),
            $data['buyerReference'],
        )
            ->setNumber($data['number'])
            ->setNote($data['note'])
            ->setPaymentReference($data['payment']['reference'] ?? null)
            ->setOrigin($origin)
            // Another currency than the ledger's takes its rate at recording.
            ->setCurrencyCode($currency);

        if ($data['dueDate']) {
            $invoice->setDateDue(new DateTimeImmutable($data['dueDate']));
        }

        foreach ($data['lines'] as $line) {
            $this->addLine($invoice, $line, $money);
        }

        $discount = array_sum(array_map(fn (array $allowance) => $money($allowance['amount']), $data['allowances']));
        if (0 !== $discount) {
            $invoice->setPriceDiscount($discount, PriceUnit::Money);
        }

        $stated = $money($data['totals']['total']);
        if ($stated !== $invoice->calcPriceFinal()) {
            $warnings[] = sprintf('The supplier states a total of %d, the lines give %d.', $stated, $invoice->calcPriceFinal());
        }

        if ($data['embeddedDocument']) {
            $invoice->setMetadata($invoice->getMetadata() + ['embedded_document' => true]);
        }

        return new ElectronicInvoiceImportResult($invoice, $created, $warnings);
    }

    private function addLine(
        Invoice $invoice,
        array $line,
        callable $money
    ): void {
        $category = $line['category'];

        $item = $this->invoiceFactory->addItem(
            $invoice,
            $line['name'] ?? 'Line',
            $money($line['price']),
            (int) round((float) ($line['quantity'] ?? 1) * InvoiceItem::QUANTITY_SCALE),
            in_array($category['id'], ['S', 'Z'], true) ? (int) round((float) $category['rate'] * 100) : 0,
        );
        $item->setDescription($line['description']);

        if ($line['discount'] && 0 !== $discount = $money($line['discount']['amount'])) {
            $item->setPriceDiscount($discount, PriceUnit::Money);
        }
    }

    /**
     * @return array{0: Party, 1: bool}
     */
    private function findOrCreateSupplier(
        Ledger $ledger,
        array $seller,
        ?string $iban
    ): array {
        $vatNumber = $seller['vatNumber'] ? strtoupper(preg_replace('/[\s.\-]/', '', $seller['vatNumber'])) : null;
        $country = $seller['country'] ? $this->countryRepository->findByIsoAlpha2Code(strtoupper($seller['country'])) : null;
        $legalIdentifier = $seller['legalIdentifier']['id'] ?? null;

        // The seller may already be in the directory (a customer, another ledger's supplier).
        $organization = $this->organizationService->find($country, $legalIdentifier, $vatNumber);

        if ($organization && $existing = $this->partyRepository->findOneByOrganization($ledger, $organization)) {
            $existing->setSupplier(true);
            $this->partyService->assignCodes($existing);

            return [$existing, false];
        }

        if (! $organization) {
            $organization = $this->organizationService->create($seller['name'] ?: 'Supplier', $country ?? $ledger->getCountry(), $legalIdentifier, $vatNumber);

            // The address printed on the document, as a row the supplier
            // points at (saved with it, cascade).
            $organization->setAddress($this->addressRepository->createNewAddress(
                implode("\n", $seller['addressLines']) ?: null,
                $seller['postCode'],
                $seller['city'],
                $country,
            ));
        }

        $supplier = $this->partyService->create($ledger, $organization, customer: false, supplier: true);
        $supplier->setIban($iban);

        return [$supplier, true];
    }
}
