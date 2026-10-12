<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Event\InvoiceDocumentEvent;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyRemotePdfFactory\Class\PdfAttachment;
use Wexample\SymfonyRemotePdfFactory\Class\PdfOutput;
use Wexample\SymfonyRemotePdfFactory\Class\PdfTheme;
use Wexample\SymfonyRemotePdfFactory\Service\PdfFactoryClient;

/**
 * An invoice as a PDF: its data, its semantic document, the charter (the default
 * one, then the application's stylesheets), its data embedded as `invoice.json`
 * so the PDF can be checked against the books, a Factur-X output where the
 * jurisdiction asks for it (an emitted invoice only), the application's last word
 * through InvoiceDocumentEvent, and pdf-factory's rendering.
 */
class InvoicePdfService
{
    /**
     * @param list<string> $stylesheets Paths, in order: the later ones override the earlier.
     */
    public function __construct(
        private readonly InvoiceDocumentDataBuilder $dataBuilder,
        private readonly PdfFactoryDocumentBuilder $documentBuilder,
        private readonly PdfFactoryClient $client,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
        private readonly ElectronicInvoiceDataBuilder $electronicDataBuilder,
        private readonly CiiInvoiceBuilder $ciiBuilder,
        private readonly array $stylesheets,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * What would be posted, document and theme, without rendering it.
     */
    public function prepare(
        Invoice $invoice,
        ?string $locale = null
    ): InvoiceDocumentEvent {
        $data = $this->dataBuilder->build($invoice, $locale);

        return $this->eventDispatcher->dispatch(new InvoiceDocumentEvent(
            $invoice,
            $locale,
            $data,
            $this->documentBuilder->build($data, $locale),
            PdfTheme::fromFiles($this->stylesheets, [
                'pagination-before' => $this->trans('document.pagination.before', $locale),
                'pagination-between' => $this->trans('document.pagination.between', $locale),
            ]),
            [PdfAttachment::json('invoice.json', $data, $this->trans('document.attachment.data', $locale))],
            $this->facturX($invoice),
        ));
    }

    /**
     * `Alternative`: from the BASIC profile up, the XML is the same invoice as the page.
     */
    private function facturX(Invoice $invoice): ?PdfOutput
    {
        if (! $this->electronicDataBuilder->supports($invoice) || ! $this->jurisdictionRegistry->forLedger($invoice->getLedger())->isFacturXEmbedded($invoice->getLedger())) {
            return null;
        }

        return PdfOutput::facturX($this->ciiBuilder->build($invoice), PdfOutput::FACTURX_EN16931, PdfAttachment::RELATIONSHIP_ALTERNATIVE);
    }

    /**
     * @return string The PDF bytes.
     */
    public function render(
        Invoice $invoice,
        ?string $locale = null
    ): string {
        $prepared = $this->prepare($invoice, $locale);

        return $this->client->render($prepared->document, $prepared->theme, $prepared->attachments, $prepared->output);
    }

    private function trans(
        string $key,
        ?string $locale
    ): string {
        return $this->translator?->trans($key, [], 'accounting', $locale) ?? $key;
    }
}
