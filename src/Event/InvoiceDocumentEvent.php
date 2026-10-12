<?php

namespace Wexample\SymfonyAccounting\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyRemotePdfFactory\Class\PdfTheme;

/**
 * Dispatched before an invoice document is rendered: where an application puts
 * its brand. It may rework the document (a letterhead section, roles of its own)
 * and the theme (a stylesheet, a logo as a block) — the books stay untouched.
 */
class InvoiceDocumentEvent extends Event
{
    /**
     * @param array $data     What InvoiceDocumentDataBuilder gave, for reading.
     * @param array $document The pdf-factory `doc` about to be posted.
     */
    public function __construct(
        public readonly Invoice $invoice,
        public readonly ?string $locale,
        public readonly array $data,
        public array $document,
        public PdfTheme $theme,
    ) {
    }
}
