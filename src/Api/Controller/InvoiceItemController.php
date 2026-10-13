<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\CreateInvoiceItemDto;
use Wexample\SymfonyAccounting\Api\Dto\ImportInvoiceItemsDto;
use Wexample\SymfonyAccounting\Api\Dto\InvoiceDto;
use Wexample\SymfonyAccounting\Api\Dto\InvoiceItemInputDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\Invoice\DefaultInvoiceNormalizer;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceLineImportService;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyRemoteDocumentReader\Exception\UnreadableDocumentException;
use Wexample\SymfonyRemoteDocumentReader\Service\DocumentReaderClient;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
use Wexample\SymfonyApi\Api\Attribute\ValidateRequestContent;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;

/**
 * The lines of a draft. Each change answers with the whole document, its
 * totals computed again. Lines also come from a table: pasted rows, or a file
 * whose tables are read first (`read-table`) so the columns can be chosen.
 */
#[Route(path: 'api/invoice-item/', name: 'api_invoice_item_')]
class InvoiceItemController extends AbstractAccountingApiController
{
    final public const string ROUTE_IMPORT = 'import';

    final public const string ROUTE_READ_TABLE = 'read_table';

    /**
     * Lines added from a table's rows, every row checked before any is added.
     */
    #[Route(path: 'import', name: self::ROUTE_IMPORT, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: ImportInvoiceItemsDto::class, attributeName: 'importInvoiceItemsDto')]
    #[ApiResponseData(InvoiceDto::class)]
    public function import(
        ImportInvoiceItemsDto $importInvoiceItemsDto,
        InvoiceRepository $repository,
        InvoiceLineImportService $importService,
        EntityManagerInterface $entityManager,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        $invoice = $this->reachable($repository->find($importInvoiceItemsDto->invoice), LedgerPermission::EDIT);

        $this->withinTheRules(fn () => $importService->import($invoice, $importInvoiceItemsDto->rows, $importInvoiceItemsDto->columns));
        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($invoice));
    }

    /**
     * The tables of a file (`file`, multipart: CSV, XLSX, ODS, DOCX, PDF…) as rows
     * of cell texts, read by the document-reader service. Nothing is saved.
     */
    #[Route(path: 'read-table', name: self::ROUTE_READ_TABLE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    public function readTable(
        Request $request,
        DocumentReaderClient $documentReader,
    ): ApiResponse {
        $this->resolveLedger($request, LedgerPermission::EDIT);
        $file = $request->files->get('file');

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw new BadRequestHttpException('Send the table as the multipart field "file".');
        }

        // The service recognises the format by the extension, and wants a plain name.
        $extension = strtolower($file->getClientOriginalExtension()) ?: 'csv';

        try {
            $reading = $documentReader->read('table.'.preg_replace('/[^a-z0-9]/', '', $extension), (string) file_get_contents($file->getPathname()));
        } catch (UnreadableDocumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        return self::apiResponseSuccess(data: ['tables' => $reading->getTables()]);
    }

    #[Route(path: 'create', name: self::ROUTE_CREATE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: CreateInvoiceItemDto::class, attributeName: 'createInvoiceItemDto')]
    #[ApiResponseData(InvoiceDto::class)]
    public function create(
        CreateInvoiceItemDto $createInvoiceItemDto,
        InvoiceRepository $repository,
        InvoiceFactory $factory,
        EntityManagerInterface $entityManager,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        $invoice = $this->reachable($repository->find($createInvoiceItemDto->invoice), LedgerPermission::EDIT);

        $this->withinTheRules(fn () => $factory->addItem(
            $invoice,
            $createInvoiceItemDto->title,
            $createInvoiceItemDto->unitPrice,
            $createInvoiceItemDto->quantity,
            $createInvoiceItemDto->vatRate,
            $createInvoiceItemDto->goods,
        )->setDescription($createInvoiceItemDto->description)->setDomesticReverseCharge($createInvoiceItemDto->domesticReverseCharge));

        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($invoice));
    }

    /**
     * A line changed in place, keeping its position; only the fields sent change.
     */
    #[Route(path: 'update/{id}', name: self::ROUTE_UPDATE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: InvoiceItemInputDto::class, attributeName: 'invoiceItemInputDto')]
    #[ApiResponseData(InvoiceDto::class)]
    public function update(
        string $id,
        Request $request,
        InvoiceItemInputDto $invoiceItemInputDto,
        InvoiceFactory $factory,
        EntityManagerInterface $entityManager,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        $item = $entityManager->find(InvoiceItem::class, $id) ?? throw new NotFoundHttpException();
        $invoice = $this->reachable($item->getInvoice(), LedgerPermission::EDIT);

        if (! $invoice->isEditable()) {
            throw new BadRequestHttpException('An emitted document cannot change: issue a credit note.');
        }

        $required = fn (mixed $value, string $field): mixed => $value ?? throw new BadRequestHttpException(sprintf('The field "%s" cannot be null.', $field));

        foreach (self::sentFields($request) as $field) {
            match ($field) {
                'title' => $item->setTitle($required($invoiceItemInputDto->title, $field)),
                'description' => $item->setDescription($invoiceItemInputDto->description),
                'reference' => $item->setReference($invoiceItemInputDto->reference),
                'unitPrice' => $item->setPriceRaw($required($invoiceItemInputDto->unitPrice, $field)),
                'quantity' => $item->setQuantity($required($invoiceItemInputDto->quantity, $field)),
                'unit' => $item->setUnit($invoiceItemInputDto->unit),
                'vatRate' => $item->setPriceVat($invoiceItemInputDto->vatRate ?? $factory->defaultVatRate($invoice->getLedger())),
                'goods' => $item->setGoods($required($invoiceItemInputDto->goods, $field)),
                'domesticReverseCharge' => $item->setDomesticReverseCharge($required($invoiceItemInputDto->domesticReverseCharge, $field)),
                default => null,
            };
        }

        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($invoice));
    }

    #[Route(path: 'delete/{id}', name: self::ROUTE_DELETE, methods: [Request::METHOD_DELETE], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(InvoiceDto::class)]
    public function delete(
        string $id,
        EntityManagerInterface $entityManager,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        $item = $entityManager->find(InvoiceItem::class, $id) ?? throw new NotFoundHttpException();
        $invoice = $this->reachable($item->getInvoice(), LedgerPermission::EDIT);

        if (! $invoice->isEditable()) {
            throw new BadRequestHttpException('An emitted document cannot change: issue a credit note.');
        }

        $invoice->getItems()->removeElement($item);
        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($invoice));
    }
}
