<?php

namespace Wexample\SymfonyAccounting\Service\Bank\Matcher;

use Symfony\Component\Uid\Uuid;
use Wexample\SymfonyAccounting\Class\MatchProposal;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Interface\TransactionMatcherInterface;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyPayment\Repository\PaymentRepository;

/**
 * A provider line carrying the payment it settles (Stripe PaymentIntent):
 * when that payment paid a document, or a payable invoiced with it (a cart),
 * the line pays that document. Certain.
 */
class ProviderPaymentMatcher implements TransactionMatcherInterface
{
    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly InvoiceRepository $invoiceRepository,
    ) {
    }

    public function getName(): string
    {
        return 'provider_payment';
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function propose(BankTransaction $transaction): array
    {
        $reference = $transaction->getPaymentReference();

        if (! $reference || $transaction->getAmount() <= 0) {
            return [];
        }

        $payment = $this->paymentRepository->findOneByProviderReference($reference);

        if (! $payment) {
            return [];
        }

        // A document paid itself; anything else paid was invoiced with its payment.
        $invoice = Invoice::PAYABLE_TYPE === $payment->getPayableType()
            ? (Uuid::isValid((string) $payment->getPayableId()) ? $this->invoiceRepository->find(Uuid::fromString($payment->getPayableId())) : null)
            : $this->invoiceRepository->findBillForPayment($payment);

        if (! $invoice || $invoice->getLedger() !== $transaction->getLedger() || $invoice->calcRemainingAmount() <= 0) {
            return [];
        }

        return [new MatchProposal($this->getName(), 100, invoice: $invoice, autoValidate: true)];
    }
}
