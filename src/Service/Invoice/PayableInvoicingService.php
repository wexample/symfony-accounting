<?php

namespace Wexample\SymfonyAccounting\Service\Invoice;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
use Wexample\SymfonyAccounting\Service\Ledger\PartyService;
use Wexample\SymfonyAccounting\Service\Ledger\SalesLedgerProvider;
use Wexample\SymfonyGeo\Repository\CountryRepository;
use Wexample\SymfonyMoney\Helper\RateHelper;
use Wexample\SymfonyMoney\Interface\DiscountedInterface;
use Wexample\SymfonyMoney\Interface\PricedLineInterface;
use Wexample\SymfonyMoney\Interface\PricedParentInterface;
use Wexample\SymfonyPayment\Entity\Payment;
use Wexample\SymfonyPayment\Entity\PaymentRefund;
use Wexample\SymfonyPayment\Interface\BuyerIdentifiedInterface;
use Wexample\SymfonyPayment\Interface\PayableInterface;
use Wexample\SymfonyPayment\Service\PayableRegistry;
use Wexample\SymfonyOrganization\Service\OrganizationService;
use Wexample\SymfonyOrganization\Service\VatNumberVerifier;

/**
 * Invoices what was paid, whatever it is: a cart, a subscription, a registration.
 *
 * The payable is read through money's and payment's contracts only — its lines as
 * PricedLineInterface, its buyer as BuyerIdentifiedInterface — so this package
 * never knows the class that sold. One bill per payment, in the sales ledger,
 * emitted; a refund issues a credit note on it.
 */
class PayableInvoicingService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SalesLedgerProvider $salesLedgerProvider,
        private readonly PayableRegistry $payableRegistry,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PartyRepository $partyRepository,
        private readonly PartyService $partyService,
        private readonly CountryRepository $countryRepository,
        private readonly InvoiceFactory $invoiceFactory,
        private readonly InvoiceEmissionService $emissionService,
        private readonly VatNumberVerifier $vatNumberVerifier,
        private readonly OrganizationService $organizationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The bill of a paid payable. Idempotent. Null without a sales ledger, for a
     * document paid (an invoice is its own bill), or for a payable without lines.
     * A bill that cannot be emitted (no exchange rate, no fiscal year) stays a
     * draft for the accountant, and is still returned.
     */
    public function invoice(Payment $payment): ?Invoice
    {
        $ledger = $this->salesLedgerProvider->getLedger();

        if (null === $ledger || Invoice::PAYABLE_TYPE === $payment->getPayableType()) {
            return null;
        }

        $existing = $this->invoiceRepository->findBillForPayment($payment);

        if ($existing) {
            return $existing;
        }

        $payable = $this->payableRegistry->resolve($payment);

        if (! $payable instanceof PricedParentInterface) {
            return null;
        }

        $lines = [...$payable->getPricedChildren()];

        foreach ($lines as $line) {
            if (! $line instanceof PricedLineInterface) {
                $this->logger->warning('A {type} line is no PricedLineInterface: payment {payment} left uninvoiced.', [
                    'type' => $payment->getPayableType(),
                    'payment' => (string) $payment->getId(),
                ]);

                return null;
            }
        }

        $invoice = $this->invoiceFactory->create(
            $ledger,
            party: $this->resolveParty($ledger, $payable, $payment),
            dateInvoice: $payment->getDatePaid(),
            title: $payable->getPayableDescription(),
        );
        $invoice
            ->setPayment($payment)
            ->setOrigin((string) $payment->getPayableType())
            ->setCurrencyCode($payment->getCurrencyCode())
            ->setMetadata(['payable_type' => $payment->getPayableType(), 'payable_id' => $payment->getPayableId()]);

        foreach ($lines as $line) {
            $this->copyLine($invoice, $line);
        }

        if ($payable instanceof DiscountedInterface && $payable->getPriceDiscount()) {
            $invoice->setPriceDiscount($payable->getPriceDiscount(), $payable->getPriceDiscountUnit());
        }

        $invoice->updatePriceTotal();
        $this->entityManager->flush();

        try {
            $this->emissionService->emit($invoice);
        } catch (AccountingException $exception) {
            $this->logger->warning('The bill of payment {payment} stays a draft: {reason}', [
                'payment' => (string) $payment->getId(),
                'reason' => $exception->getMessage(),
            ]);

            return $invoice;
        }

        if ($invoice->calcPriceFinal() !== $payment->getAmount()) {
            // The rules applied at emission saw the buyer otherwise than checkout did.
            $this->logger->warning('The bill {number} asks {billed}, the payment brought {paid}.', [
                'number' => $invoice->getNumber(),
                'billed' => $invoice->calcPriceFinal(),
                'paid' => $payment->getAmount(),
            ]);
        }

        return $invoice;
    }

    /**
     * The credit note of a refund on an invoiced payment: the whole bill back
     * when the whole payment is, else the refunded amount at the bill's main rate.
     * Null when the payment has no emitted bill.
     */
    public function credit(
        Payment $payment,
        PaymentRefund $refund
    ): ?Invoice {
        $bill = $this->invoiceRepository->findBillForPayment($payment);

        if (null === $bill || ! $bill->getStatus()->isEmitted()) {
            return null;
        }

        $amount = $refund->getAmount() >= $payment->getAmount()
            ? null
            : RateHelper::extractBase($refund->getAmount(), array_key_last($bill->calcVatLines()) ?? 0);

        $credit = $this->invoiceFactory->createCreditNote($bill, $amount, $refund->getReason());
        $credit
            ->setPayment($payment)
            ->setOrigin($bill->getOrigin())
            ->setCurrencyCode($bill->getCurrencyCode());
        $this->entityManager->flush();

        return $this->emissionService->emit($credit);
    }

    private function copyLine(
        Invoice $invoice,
        PricedLineInterface $line
    ): void {
        $item = $this->invoiceFactory->addItem(
            $invoice,
            $line->getTitle(),
            $line->getPriceRaw() ?? 0,
            RateHelper::mulDiv($line->getQuantity() ?? 0, InvoiceItem::QUANTITY_SCALE, $line->getQuantityScale()),
            $line->getPriceVat(),
            $line->isGoods(),
            $line->getAccountNumber(),
        );

        if ($line instanceof DiscountedInterface && $line->getPriceDiscount()) {
            $item->setPriceDiscount($line->getPriceDiscount(), $line->getPriceDiscountUnit());
        }

        if (null !== $line->getPriceOverridden()) {
            $item->setPriceOverridden($line->getPriceOverridden());
        }
    }

    /**
     * The customer the buyer already is in the books, else a new one. The VAT
     * number is kept only once VIES confirms it, as checkout charged it: a number
     * nobody checked would lift the VAT the buyer paid.
     */
    private function resolveParty(
        Ledger $ledger,
        PayableInterface $payable,
        Payment $payment
    ): Party {
        $buyer = $payable instanceof BuyerIdentifiedInterface ? $payable : null;
        $email = $buyer?->getBuyerEmail() ?? $payment->getCustomerEmail();
        $vatNumber = $buyer?->getBuyerVatNumber() ? strtoupper((string) preg_replace('/[\s.\-]/', '', $buyer->getBuyerVatNumber())) : null;

        if (null !== $vatNumber && ! $this->vatNumberVerifier->isConfirmed($vatNumber)) {
            $vatNumber = null;
        }

        $party = $this->partyRepository->findOneCustomer($ledger, $vatNumber, $email);

        if ($party) {
            return $party;
        }

        $address = $buyer?->getBuyerAddress();
        $country = $address?->getCountryCode() ? $this->countryRepository->findByIsoAlpha2Code($address->getCountryCode()) : null;
        $company = $buyer?->getBuyerCompanyName();

        // Already in the directory — a customer of another ledger, a supplier —, or new.
        $organization = $this->organizationService->find($country, vatNumber: $vatNumber);

        if (! $organization) {
            $organization = $this->organizationService->create($company ?? $buyer?->getBuyerName() ?? $email ?? 'Customer', $country ?? $ledger->getCountry(), vatNumber: $vatNumber)
                ->setIndividual(null === $company)
                ->setEmail($email)
                ->setPhone($buyer?->getBuyerPhone())
                ->setPostalAddress($address?->getPostalAddress())
                ->setPostCode($address?->getPostCode())
                ->setCity($address?->getCity());
        }

        return $this->partyService->findOrCreate($ledger, $organization, customer: true);
    }
}
