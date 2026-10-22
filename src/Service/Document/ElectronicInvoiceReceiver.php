<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use Wexample\SymfonyAccounting\Class\ElectronicInvoiceImportResult;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyRemoteDocumentReader\Exception\UnreadableDocumentException;

/**
 * A received electronic invoice, whatever form it came in, made a purchase draft:
 * a Factur-X PDF, a CII XML, a UBL XML. The form is told by the content, never by
 * the file name.
 */
class ElectronicInvoiceReceiver
{
    public function __construct(
        private readonly UblInvoiceReader $ublReader,
        private readonly CiiInvoiceReader $ciiReader,
        private readonly FacturXInvoiceReader $facturXReader,
    ) {
    }

    public function receive(
        Ledger $ledger,
        string $content
    ): ElectronicInvoiceImportResult {
        if (str_contains(substr($content, 0, 1024), '%PDF-')) {
            try {
                return $this->facturXReader->read($ledger, $content);
            } catch (UnreadableDocumentException $exception) {
                throw new AccountingException('This PDF cannot be read: '.$exception->getMessage(), previous: $exception);
            }
        }

        if (preg_match('/<(?:[\w-]+:)?(CrossIndustryInvoice|Invoice|CreditNote)[\s>]/', $content, $root)) {
            return 'CrossIndustryInvoice' === $root[1]
                ? $this->ciiReader->read($ledger, $content)
                : $this->ublReader->read($ledger, $content);
        }

        throw new AccountingException('This is neither a Factur-X PDF nor a CII or UBL invoice.');
    }
}
