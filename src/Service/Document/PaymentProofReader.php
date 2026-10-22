<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use DateTimeImmutable;
use Wexample\SymfonyMedia\Service\MediaStorage;
use Wexample\SymfonyPayment\Entity\Payment;
use Wexample\SymfonyRemoteDocumentReader\Service\DocumentReaderClient;

/**
 * Reads the proof a payer sent for a manual payment — a transfer receipt, a scan —
 * through document-reader, and says whether it shows the payment's amount and one
 * of its references. A help for whoever confirms the money, never a confirmation.
 */
class PaymentProofReader
{
    public function __construct(
        private readonly DocumentReaderClient $documentReader,
        private readonly MediaStorage $mediaStorage,
    ) {
    }

    /**
     * @return array{amount_found: bool, reference_found: bool, date_read: string}|null Null without a proof, or without a reader.
     */
    public function read(Payment $payment): ?array
    {
        $proof = $payment->getProof();

        if (null === $proof || ! $this->documentReader->isConfigured()) {
            return null;
        }

        $reading = $this->documentReader->read($proof->getOriginalName(), (string) file_get_contents($this->mediaStorage->getPath($proof)));
        $text = self::text($reading->document);
        // Thousands separators and line breaks dropped: "1 234,56" reads as "1234,56".
        $compact = (string) preg_replace('/\s+/u', '', $text);
        $units = intdiv($payment->getAmount(), 100);
        $cents = sprintf('%02d', $payment->getAmount() % 100);
        $references = array_filter([
            (string) $payment->getId(),
            $payment->getPayableId(),
            $payment->getProviderReference(),
            $payment->getProviderChargeReference(),
        ]);

        return [
            'amount_found' => str_contains($compact, $units.','.$cents) || str_contains($compact, $units.'.'.$cents),
            'reference_found' => [] !== array_filter($references, fn (string $reference) => false !== stripos($text, $reference)),
            'date_read' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /**
     * Every text of a document node, in reading order.
     */
    private static function text(array $node): string
    {
        if ('text' === ($node['type'] ?? null)) {
            return $node['text'];
        }

        return implode(' ', array_map(self::text(...), $node['content'] ?? []));
    }
}
