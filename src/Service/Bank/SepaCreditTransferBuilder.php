<?php

namespace Wexample\SymfonyAccounting\Service\Bank;

use DateTimeImmutable;
use DOMDocument;
use Wexample\SymfonyAccounting\Class\SepaPayment;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Helper\IbanHelper;
use Wexample\SymfonyMoney\Helper\MoneyHelper;

/**
 * A SEPA credit transfer file (ISO 20022 pain.001.001.03), the file every
 * European bank accepts to pay a batch of suppliers at once.
 */
class SepaCreditTransferBuilder
{
    /**
     * One payment per purchase, for what is left to pay, to the supplier's IBAN,
     * with the reference the supplier asked for or the invoice number.
     *
     * @param iterable<Invoice> $purchases
     * @return list<SepaPayment>
     */
    public function paymentsForPurchases(iterable $purchases): array
    {
        $payments = [];

        foreach ($purchases as $invoice) {
            $party = $invoice->getParty();
            $remaining = $invoice->calcRemainingAmount();

            if ($invoice->isSale() || $remaining <= 0) {
                continue;
            }

            if (! $party?->getIban()) {
                throw new AccountingException(sprintf('Supplier "%s" has no IBAN.', $party?->getName()));
            }

            $reference = $invoice->getPaymentReference();
            $structured = null !== $reference && preg_match('/^(\+\+\+|RF)/', $reference);

            $payments[] = new SepaPayment(
                endToEndId: substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string) $invoice->getNumber()) ?: (string) $invoice->getId(), 0, 35),
                amount: $remaining,
                creditorName: $party->getName(),
                creditorIban: $party->getIban(),
                remittance: $structured ? null : ($reference ?? $invoice->getNumber()),
                structuredReference: $structured ? $reference : null,
            );
        }

        return $payments;
    }

    /**
     * @param list<SepaPayment> $payments
     */
    public function build(
        BankAccount $debtor,
        array $payments,
        DateTimeImmutable $executionDate,
        ?string $messageId = null
    ): string {
        if ([] === $payments) {
            throw new AccountingException('A payment file needs at least one payment.');
        }

        if (! $debtor->getIban() || ! IbanHelper::isValid($debtor->getIban())) {
            throw new AccountingException('The paying account has no valid IBAN.');
        }

        foreach ($payments as $payment) {
            if (! IbanHelper::isValid($payment->creditorIban)) {
                throw new AccountingException(sprintf('Invalid IBAN for %s: %s.', $payment->creditorName, $payment->creditorIban));
            }

            if ($payment->amount <= 0) {
                throw new AccountingException(sprintf('The payment to %s must be positive.', $payment->creditorName));
            }
        }

        $messageId ??= 'SCT'.date('YmdHis').substr(bin2hex(random_bytes(4)), 0, 8);
        $total = array_sum(array_map(fn (SepaPayment $p) => $p->amount, $payments));
        $holder = $this->text($debtor->getHolder() ?? $debtor->getLedger()->getName(), 70);
        $decimal = fn (int $amount) => MoneyHelper::toDecimal($amount, 'EUR');

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $document = $xml->appendChild($xml->createElementNS('urn:iso:std:iso:20022:tech:xsd:pain.001.001.03', 'Document'));
        $root = $document->appendChild($xml->createElement('CstmrCdtTrfInitn'));

        $header = $root->appendChild($xml->createElement('GrpHdr'));
        $this->add($xml, $header, 'MsgId', $messageId);
        $this->add($xml, $header, 'CreDtTm', (new DateTimeImmutable())->format('Y-m-d\TH:i:s'));
        $this->add($xml, $header, 'NbOfTxs', (string) count($payments));
        $this->add($xml, $header, 'CtrlSum', $decimal($total));
        $this->add($xml, $this->add($xml, $header, 'InitgPty'), 'Nm', $holder);

        $info = $root->appendChild($xml->createElement('PmtInf'));
        $this->add($xml, $info, 'PmtInfId', $messageId.'-1');
        $this->add($xml, $info, 'PmtMtd', 'TRF');
        $this->add($xml, $info, 'NbOfTxs', (string) count($payments));
        $this->add($xml, $info, 'CtrlSum', $decimal($total));
        $this->add($xml, $this->add($xml, $this->add($xml, $info, 'PmtTpInf'), 'SvcLvl'), 'Cd', 'SEPA');
        $this->add($xml, $info, 'ReqdExctnDt', $executionDate->format('Y-m-d'));
        $this->add($xml, $this->add($xml, $info, 'Dbtr'), 'Nm', $holder);
        $this->add($xml, $this->add($xml, $this->add($xml, $info, 'DbtrAcct'), 'Id'), 'IBAN', IbanHelper::normalize($debtor->getIban()));
        $agent = $this->add($xml, $this->add($xml, $info, 'DbtrAgt'), 'FinInstnId');
        $debtor->getBic()
            ? $this->add($xml, $agent, 'BIC', $debtor->getBic())
            : $this->add($xml, $this->add($xml, $agent, 'Othr'), 'Id', 'NOTPROVIDED');
        $this->add($xml, $info, 'ChrgBr', 'SLEV');

        foreach ($payments as $payment) {
            $transaction = $this->add($xml, $info, 'CdtTrfTxInf');
            $this->add($xml, $this->add($xml, $transaction, 'PmtId'), 'EndToEndId', $this->text($payment->endToEndId, 35));
            $amount = $this->add($xml, $this->add($xml, $transaction, 'Amt'), 'InstdAmt', $decimal($payment->amount));
            $amount->setAttribute('Ccy', 'EUR');

            if ($payment->creditorBic) {
                $this->add($xml, $this->add($xml, $this->add($xml, $transaction, 'CdtrAgt'), 'FinInstnId'), 'BIC', $payment->creditorBic);
            }

            $this->add($xml, $this->add($xml, $transaction, 'Cdtr'), 'Nm', $this->text($payment->creditorName, 70));
            $this->add($xml, $this->add($xml, $this->add($xml, $transaction, 'CdtrAcct'), 'Id'), 'IBAN', IbanHelper::normalize($payment->creditorIban));

            $remittance = $this->add($xml, $transaction, 'RmtInf');

            if ($payment->structuredReference) {
                $reference = $this->add($xml, $this->add($xml, $remittance, 'Strd'), 'CdtrRefInf');
                $type = $this->add($xml, $reference, 'Tp');
                $this->add($xml, $this->add($xml, $type, 'CdOrPrtry'), 'Cd', 'SCOR');

                if (! str_starts_with($payment->structuredReference, 'RF')) {
                    // Belgian structured communication, as Belgian banks expect it.
                    $this->add($xml, $type, 'Issr', 'BBA');
                }

                $this->add($xml, $reference, 'Ref', preg_replace('/[^0-9A-Z]/', '', $payment->structuredReference));
            } else {
                $this->add($xml, $remittance, 'Ustrd', $this->text((string) $payment->remittance, 140));
            }
        }

        return $xml->saveXML();
    }

    private function add(
        DOMDocument $xml,
        \DOMNode $parent,
        string $name,
        ?string $value = null
    ): \DOMElement {
        $element = $xml->createElement($name);

        if (null !== $value) {
            $element->appendChild($xml->createTextNode($value));
        }

        return $parent->appendChild($element);
    }

    /**
     * The SEPA character set: Latin letters, digits and a few signs.
     */
    private function text(
        string $value,
        int $length
    ): string {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return mb_substr(trim(preg_replace('/[^A-Za-z0-9\/\-\?:\(\)\.,\'\+ ]/', ' ', $ascii)), 0, $length);
    }
}
