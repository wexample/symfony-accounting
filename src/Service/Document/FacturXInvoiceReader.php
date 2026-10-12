<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use Wexample\SymfonyAccounting\Class\ElectronicInvoiceImportResult;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyRemoteDocumentReader\Service\DocumentReaderClient;

/**
 * Reads a received Factur-X (or ZUGFeRD 2) PDF: the document-reader service takes
 * its XML out, in its sandbox, and CiiInvoiceReader makes it a purchase draft.
 */
class FacturXInvoiceReader
{
    public function __construct(
        private readonly DocumentReaderClient $documentReader,
        private readonly CiiInvoiceReader $ciiReader,
    ) {
    }

    /**
     * @param string $pdf The PDF bytes.
     */
    public function read(
        Ledger $ledger,
        string $pdf
    ): ElectronicInvoiceImportResult {
        $extraction = $this->documentReader->extract($pdf);
        $xml = $extraction->getFacturXXml();

        if (null === $xml) {
            throw new AccountingException('This PDF carries no structured invoice.');
        }

        $result = $this->ciiReader->read($ledger, $xml, 'factur-x');

        if (! $extraction->facturx['declared']) {
            return new ElectronicInvoiceImportResult($result->invoice, $result->partyCreated, [
                ...$result->warnings,
                sprintf('The PDF embeds %s without declaring it: not a valid Factur-X.', $extraction->facturx['fileName']),
            ]);
        }

        return $result;
    }
}
