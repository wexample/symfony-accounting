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
use Wexample\SymfonyGeo\Helper\PostalAddressHelper;

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

        if ($invoice->getCurrencyCode() !== $invoice->getLedger()->getCurrencyCode()) {
            throw new AccountingException('Documents in another currency than the ledger\'s are not supported yet.');
        }
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
            'legalMentions' => $ledger->getLegalMentions(),
            'address' => PostalAddressHelper::toArray($ledger),
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
            'customerCode' => $party->getCustomerCode(),
            'supplierCode' => $party->getSupplierCode(),
            'address' => PostalAddressHelper::toArray($party),
        ];
    }
}
