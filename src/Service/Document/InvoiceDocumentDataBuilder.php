<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use Symfony\Contracts\Translation\TranslatorInterface;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Enum\InvoiceRelationType;
use Wexample\SymfonyAccounting\Repository\InvoiceRelationRepository;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyGeo\Interface\PostalAddressInterface;
use Wexample\SymfonyGeo\Helper\PostalAddressHelper;
use Wexample\SymfonyMoney\Service\MoneyFormatter;

/**
 * Everything a printed document shows, as plain data: who, what, how much, which
 * legal mentions, where to pay. The contract between the books and whatever
 * renders documents (pdf-factory, an HTML preview, an e-invoicing export).
 *
 * Amounts are given twice: in minor units, and formatted for the locale.
 */
class InvoiceDocumentDataBuilder
{
    public function __construct(
        private readonly JurisdictionRegistry $jurisdictionRegistry,
        private readonly InvoiceRelationRepository $relationRepository,
        private readonly MoneyFormatter $moneyFormatter,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function build(
        Invoice $invoice,
        ?string $locale = null
    ): array {
        $ledger = $invoice->getLedger();
        $jurisdiction = $this->jurisdictionRegistry->forLedger($ledger);
        $currency = $invoice->getCurrencyCode();
        $breakdown = $invoice->calcPriceBreakdown();
        $money = fn (int $amount) => ['amount' => $amount, 'formatted' => $this->moneyFormatter->format($amount, $currency, $locale)];
        $issuer = $invoice->getIssuerSnapshot() ?? $this->liveIssuer($invoice);
        $client = $invoice->getPartySnapshot() ?? $this->liveParty($invoice);

        $items = array_map(fn (InvoiceItem $item) => [
            'title' => $item->getTitle(),
            'description' => $item->getDescription(),
            'reference' => $item->getReference(),
            'quantity' => $this->formatQuantity($item),
            'unit' => $item->getUnit(),
            'unitPrice' => $money((int) $item->getPriceRaw()),
            'vatRate' => $item->getPriceVat(),
            'vatRateFormatted' => $this->moneyFormatter->formatRate($item->getPriceVat(), $locale),
            'total' => $money($item->calcPriceNet()),
        ], $invoice->getItems()->toArray());

        $credits = [];
        foreach ($this->relationRepository->findBy(['source' => $invoice, 'type' => InvoiceRelationType::Credit]) as $relation) {
            $credit = $relation->getTarget();

            if ($credit->getStatus()->isEmitted()) {
                $credits[] = ['number' => $credit->getNumber(), 'total' => $money(-$credit->calcPriceFinal())];
            }
        }

        $mentions = array_map(fn ($mention) => [
            'key' => $mention->key,
            'placement' => $mention->placement,
            'parameters' => $mention->parameters,
            'text' => $this->trans($mention->key, $mention->parameters, $locale),
        ], $jurisdiction->getInvoiceMentions($invoice));

        $vatLines = [];
        foreach ($breakdown->vatLines as $rate => $line) {
            $vatLines[] = [
                'rate' => $rate,
                'rateFormatted' => $this->moneyFormatter->formatRate($rate, $locale),
                'base' => $money($line->base),
                'vat' => $money($line->vat),
            ];
        }

        $paid = $invoice->calcPaidAmount();

        return [
            'type' => $invoice->getType()->value,
            'direction' => $invoice->getDirection()->value,
            'title' => $this->trans('document.title.'.$invoice->getType()->value, [], $locale),
            'number' => $invoice->getNumber(),
            'subject' => $invoice->getTitle(),
            'note' => $invoice->getNote(),
            'currency' => $currency,
            'dates' => [
                'invoice' => $invoice->getDateInvoice()->format('Y-m-d'),
                'due' => $invoice->calcDateDue()->format('Y-m-d'),
                'validUntil' => $invoice->getDateValidUntil()?->format('Y-m-d'),
                'periodStart' => $invoice->getPeriodStart()?->format('Y-m-d'),
                'periodEnd' => $invoice->getPeriodEnd()?->format('Y-m-d'),
                'paid' => $invoice->getDatePaid()?->format('Y-m-d'),
            ],
            'issuer' => $this->identityBlock($issuer, $invoice->getLedger(), $jurisdiction->getLegalIdentifierLabel(), $jurisdiction->getVatNumberLabel(), $jurisdiction->isLegalIdentifierIncludedInVatNumber(), $locale),
            'client' => $this->identityBlock($client, $invoice->getParty(), $jurisdiction->getLegalIdentifierLabel(), $jurisdiction->getVatNumberLabel(), $jurisdiction->isLegalIdentifierIncludedInVatNumber(), $locale),
            'items' => $items,
            'credits' => $credits,
            'totals' => [
                'subTotal' => $money($breakdown->subTotal),
                'discount' => $breakdown->discount ? $money($breakdown->discount) : null,
                'net' => $money($breakdown->getNet()),
                'vatLines' => $vatLines,
                'vat' => $money($breakdown->getVat()),
                'total' => $money($breakdown->getTotal()),
                'overridden' => $breakdown->isOverridden() ? $money($breakdown->overridden) : null,
                'final' => $money($breakdown->getFinal()),
                'paid' => $paid ? $money($paid) : null,
                'due' => $money($invoice->calcRemainingAmount()),
            ],
            'payment' => [
                'reference' => $invoice->getPaymentReference() ?? $invoice->getNumber(),
                'termDays' => $invoice->getPaymentTermDays(),
                'bank' => $issuer['bank'] ?? null,
                'epcQr' => $this->buildEpcQrPayload($invoice, $issuer),
            ],
            'mentions' => $mentions,
            'legalMentions' => $issuer['legalMentions'] ?? null,
        ];
    }

    /**
     * The European Payments Council QR code text (SEPA credit transfer): what a
     * banking app scans to prefill the payment. Null without an IBAN.
     */
    public function buildEpcQrPayload(
        Invoice $invoice,
        array $issuer
    ): ?string {
        $bank = $issuer['bank'] ?? null;
        $due = $invoice->calcRemainingAmount();

        if (! ($bank['iban'] ?? null) || 'EUR' !== $invoice->getCurrencyCode() || ! $invoice->isSale() || $due <= 0) {
            return null;
        }

        return implode("\n", [
            'BCD',
            '002',
            '1',
            'SCT',
            (string) ($bank['bic'] ?? ''),
            mb_substr((string) ($bank['holder'] ?? $issuer['name'] ?? ''), 0, 70),
            $bank['iban'],
            'EUR'.number_format($due / 100, 2, '.', ''),
            '',
            '',
            mb_substr((string) ($invoice->getPaymentReference() ?? $invoice->getNumber()), 0, 140),
        ]);
    }

    private function identityBlock(
        array $identity,
        ?PostalAddressInterface $address,
        string $identifierLabel,
        string $vatLabel,
        bool $identifierInVat,
        ?string $locale
    ): array {
        $showIdentifier = ! ($identifierInVat && ! empty($identity['vatNumber']));

        return [
            'name' => $identity['name'] ?? null,
            'legalForm' => $identity['legalForm'] ?? null,
            'addressLines' => $address ? PostalAddressHelper::toLines($address) : [],
            'legalIdentifier' => $showIdentifier ? ($identity['legalIdentifier'] ?? null) : null,
            'legalIdentifierLabel' => $this->trans($identifierLabel, [], $locale),
            'vatNumber' => $identity['vatNumber'] ?? null,
            'vatNumberLabel' => $this->trans($vatLabel, [], $locale),
            'email' => $identity['email'] ?? null,
            'phone' => $identity['phone'] ?? null,
            'website' => $identity['website'] ?? null,
        ];
    }

    private function formatQuantity(InvoiceItem $item): string
    {
        if ($item->getDuration()) {
            return $item->getDuration();
        }

        $quantity = (int) $item->getQuantity();
        $whole = intdiv($quantity, InvoiceItem::QUANTITY_SCALE);
        $rest = $quantity % InvoiceItem::QUANTITY_SCALE;

        return 0 === $rest ? (string) $whole : rtrim(sprintf('%d.%02d', $whole, abs($rest)), '0');
    }

    private function liveIssuer(Invoice $invoice): array
    {
        $ledger = $invoice->getLedger();
        $bank = $ledger->getDefaultBankAccount();

        return [
            'name' => $ledger->getName(),
            'legalForm' => $ledger->getLegalForm(),
            'legalIdentifier' => $ledger->getLegalIdentifier(),
            'vatNumber' => $ledger->getVatNumber(),
            'email' => $ledger->getEmail(),
            'phone' => $ledger->getPhone(),
            'website' => $ledger->getWebsite(),
            'legalMentions' => $ledger->getLegalMentions(),
            'bank' => $bank ? ['holder' => $bank->getHolder() ?? $ledger->getName(), 'iban' => $bank->getIban(), 'bic' => $bank->getBic(), 'localDetails' => $bank->getLocalDetails()] : null,
        ];
    }

    private function liveParty(Invoice $invoice): array
    {
        $party = $invoice->getParty();

        return $party ? [
            'name' => $party->getName(),
            'legalIdentifier' => $party->getLegalIdentifier(),
            'vatNumber' => $party->getVatNumber(),
            'email' => $party->getEmail(),
        ] : [];
    }

    private function trans(
        string $key,
        array $parameters,
        ?string $locale
    ): string {
        if (! $this->translator) {
            return $key;
        }

        $placeholders = [];
        foreach ($parameters as $name => $value) {
            $placeholders['%'.$name.'%'] = $value;
        }

        return $this->translator->trans($key, $placeholders, 'accounting', $locale);
    }
}
