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
        $reading = $this->documentReader->read('invoice.pdf', $pdf, pages: false);
        $xml = $reading->getFacturXXml();

        if (null === $xml) {
            throw new AccountingException('This PDF carries no structured invoice.');
        }

        $result = $this->ciiReader->read($ledger, $xml, 'factur-x');

        if (! $reading->isFacturXDeclared()) {
            return new ElectronicInvoiceImportResult($result->invoice, $result->partyCreated, [
                ...$result->warnings,
                'The PDF embeds a structured invoice without declaring it as Factur-X expects: not a valid Factur-X.',
            ]);
        }

        return $result;
    }
}
