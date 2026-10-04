<?php

namespace Wexample\SymfonyAccounting\Service\Bank\Matcher;

use Wexample\SymfonyAccounting\Class\MatchProposal;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Interface\TransactionMatcherInterface;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;

/**
 * The payer gave the document's reference: its payment reference (BE structured
 * communication…) or its number, in the reference field or the label.
 */
class ReferenceMatcher implements TransactionMatcherInterface
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
    ) {
    }

    public function getName(): string
    {
        return 'reference';
    }

    public function getPriority(): int
    {
        return 90;
    }

    public function propose(BankTransaction $transaction): array
    {
        $ledger = $transaction->getLedger();
        $text = trim($transaction->getReference().' '.$transaction->getLabel());
        $references = $this->jurisdictionRegistry->forLedger($ledger)->extractPaymentReferences($text);

        if ($transaction->getReference()) {
            $references[] = $transaction->getReference();
        }

        foreach (array_unique($references) as $reference) {
            $invoice = $this->invoiceRepository->findOneByPaymentReference($ledger, $reference)
                ?? $this->invoiceRepository->findOneByNumber($ledger, $reference);

            if ($invoice && $this->fits($transaction, $invoice)) {
                return [new MatchProposal($this->getName(), 95, invoice: $invoice, autoValidate: true)];
            }
        }

        $normalizedText = $this->normalize($text);

        foreach ($this->invoiceRepository->findAwaitingPayment($ledger) as $invoice) {
            $number = $invoice->getNumber();

            if ($number && strlen($number) >= 5 && str_contains($normalizedText, $this->normalize($number)) && $this->fits($transaction, $invoice)) {
                return [new MatchProposal($this->getName(), 85, invoice: $invoice)];
            }
        }

        return [];
    }

    private function fits(
        BankTransaction $transaction,
        Invoice $invoice
    ): bool {
        return ($transaction->getAmount() <=> 0) === $invoice->getPaymentSign()
            && $invoice->calcRemainingAmount() > 0
            && $invoice->getStatus()->isEmitted();
    }

    private function normalize(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value));
    }
}
