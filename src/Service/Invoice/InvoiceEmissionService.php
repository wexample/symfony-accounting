<?php

namespace Wexample\SymfonyAccounting\Service\Invoice;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Event\InvoiceEmittedEvent;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyGeo\Entity\AddressSnapshot;
use Wexample\SymfonyMoney\Service\ExchangeRateService;
use Wexample\SymfonyOrganization\Interface\LegalIdentityInterface;

/**
 * The single place where a document becomes official.
 *
 * Emitting resolves the VAT of every item, numbers sales (purchases keep the
 * supplier's number), fixes the dates, freezes the issuer and the party into
 * snapshots, then dispatches InvoiceEmittedEvent, on which the ledger books it.
 */
class InvoiceEmissionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly InvoiceWorkflow $workflow,
        private readonly InvoiceNumberingService $numberingService,
        private readonly InvoiceVatResolver $vatResolver,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
        private readonly ExchangeRateService $exchangeRateService,
    ) {
    }

    public function emit(
        Invoice $invoice,
        ?DateTimeImmutable $dateInvoice = null
    ): Invoice {
        if (! $this->workflow->can($invoice, InvoiceStatus::Emitted)) {
            throw new AccountingException(sprintf('A document "%s" cannot be emitted.', $invoice->getStatus()->value));
        }

        if ($dateInvoice) {
            $invoice->setDateInvoice($dateInvoice);
        }

        $this->assertEmittable($invoice);
        $this->fixExchangeRate($invoice);
        $this->vatResolver->apply($invoice);
        $invoice->updatePriceTotal();

        if ($invoice->isSale()) {
            $invoice->setNumber($this->numberingService->next($invoice));
            $invoice->setIssuerSnapshot($this->snapshotLedger($invoice->getLedger()));
            $invoice->setPaymentReference(
                $invoice->getPaymentReference()
                ?? $this->jurisdictionRegistry->forLedger($invoice->getLedger())->generatePaymentReference($invoice)
            );
        }

        if ($invoice->getParty()) {
            $invoice->setPartySnapshot($this->snapshotParty($invoice->getParty()));
        }

        $invoice
            ->setDateEmitted(new DateTimeImmutable())
            ->setDateDue($invoice->calcDateDue());

        $this->workflow->transition($invoice, InvoiceStatus::Emitted, internal: true, flush: false);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new InvoiceEmittedEvent($invoice));

        return $invoice;
    }

    public function assertEmittable(Invoice $invoice): void
    {
        if ($invoice->getItems()->isEmpty()) {
            throw new AccountingException('A document needs at least one item.');
        }

        if (! $invoice->getParty()) {
            throw new AccountingException($invoice->isSale() ? 'A sale needs a customer.' : 'A purchase needs a supplier.');
        }

        if (! $invoice->isSale() && ! $invoice->getNumber()) {
            throw new AccountingException('A purchase keeps the supplier\'s number: type it before recording.');
        }

        if ($invoice->calcPriceFinal() < 0) {
            throw new AccountingException('A document total cannot be negative; emit a credit note instead.');
        }

    }

    /**
     * A document in another currency keeps the rate it was typed with, else takes
     * the provider's rate of its date (symfony-money's ExchangeRateService).
     */
    private function fixExchangeRate(Invoice $invoice): void
    {
        if (! $invoice->isForeignCurrency()) {
            $invoice->setExchangeRate(null);

            return;
        }

        if (null !== $invoice->getExchangeRate()) {
            return;
        }

        $rate = $this->exchangeRateService->getRate($invoice->getCurrencyCode(), $invoice->getLedger()->getCurrencyCode(), $invoice->getDateInvoice())
            ?? throw new AccountingException(sprintf('No exchange rate from %s to %s: type one on the document.', $invoice->getCurrencyCode(), $invoice->getLedger()->getCurrencyCode()));

        $invoice
            ->setExchangeRate($rate->rate)
            ->setMetadata($invoice->getMetadata() + ['exchange_rate' => ['source' => $rate->source, 'date' => $rate->date->format('Y-m-d')]]);
    }

    public function snapshotLedger(Ledger $ledger): array
    {
        $bank = $ledger->getDefaultBankAccount();

        return [
            'name' => $ledger->getName(),
            'legalForm' => $ledger->getLegalForm(),
            'legalIdentifier' => $ledger->getLegalIdentifier(),
            'vatNumber' => $ledger->getVatNumber(),
            'vatSubject' => $ledger->isVatSubject(),
            'email' => $ledger->getEmail(),
            'phone' => $ledger->getPhone(),
            'website' => $ledger->getWebsite(),
            'countryCode' => $ledger->getCountry()?->getIsoAlpha2Code(),
            'address' => self::addressSnapshot($ledger),
            'legalMentions' => $ledger->getLegalMentions(),
            'bank' => $bank ? [
                'label' => $bank->getLabel(),
                'holder' => $bank->getHolder() ?? $ledger->getName(),
                'iban' => $bank->getIban(),
                'bic' => $bank->getBic(),
                'bankName' => $bank->getBankName(),
                'localDetails' => $bank->getLocalDetails(),
            ] : null,
        ];
    }

    public function snapshotParty(Party $party): array
    {
        return [
            'name' => $party->getName(),
            'legalIdentifier' => $party->getLegalIdentifier(),
            'vatNumber' => $party->getVatNumber(),
            'individual' => $party->isIndividual(),
            'email' => $party->getEmail(),
            'countryCode' => $party->getCountry()?->getIsoAlpha2Code(),
            'address' => self::addressSnapshot($party),
            'customerCode' => $party->getCustomerCode(),
            'supplierCode' => $party->getSupplierCode(),
        ];
    }

    /**
     * The address as it read the day the document was issued: frozen with it,
     * so moving afterwards does not change what was sent.
     *
     * @return array<string, string|null>|null
     */
    private static function addressSnapshot(LegalIdentityInterface $identity): ?array
    {
        $address = $identity->getAddress();

        return $address ? AddressSnapshot::fromAddress($address)->toArray() : null;
    }
}
