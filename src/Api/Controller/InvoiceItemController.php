<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\CreateInvoiceItemCollectionDto;
use Wexample\SymfonyAccounting\Api\Dto\CreateInvoiceItemDto;
use Wexample\SymfonyAccounting\Api\Dto\InvoiceItemDto;
use Wexample\SymfonyAccounting\Api\Dto\InvoiceItemInputDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\InvoiceItem\DefaultInvoiceItemNormalizer;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\LengthQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\PageQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyApi\Api\Attribute\ValidateRequestContent;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;
use Wexample\SymfonyRemoteDocumentReader\Exception\UnreadableDocumentException;
use Wexample\SymfonyRemoteDocumentReader\Service\DocumentReaderClient;

/**
 * The lines of a draft, as entities: listed by document, created one by one or
 * several at once, changed in place, removed. Each change answers with the line
 * — the document's totals are read again from the document. Lines also come
 * from a table: pasted rows, or a file whose tables are read first
 * (`read-table`) so the columns can be chosen, then sent as lines (`import`).
 */
#[Route(path: '_api/invoice-item/', name: 'api_invoice_item_')]
class InvoiceItemController extends AbstractAccountingApiController
{
    final public const string ROUTE_IMPORT = 'import';

    final public const string ROUTE_READ_TABLE = 'read_table';

    final public const string QUERY_OPTION_INVOICE = 'invoice';

    /**
     * The lines of one document, in their order.
     */
    #[Route(path: 'list', name: self::ROUTE_LIST, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_INVOICE, required: true)]
    #[PageQueryOption]
    #[LengthQueryOption]
    #[ApiResponseData(InvoiceItemDto::class, paginated: true)]
    public function list(
        Request $request,
        InvoiceRepository $repository,
        EntityManagerInterface $entityManager,
        DefaultInvoiceItemNormalizer $normalizer,
    ): ApiResponse {
        $invoice = $this->reachable($repository->find((string) self::getQueryOptionValue($request, self::QUERY_OPTION_INVOICE, '')));

        $query = $entityManager->getRepository(InvoiceItem::class)->createQueryBuilder('item')
            ->andWhere('item.invoice = :invoice')
            ->setParameter('invoice', $invoice->getId(), UuidType::NAME)
            ->orderBy('item.position')
            ->addOrderBy('item.id');

        return self::apiResponseQueryPage($request, $query, static fn (array $items) => $normalizer->normalizeCollection($items));
    }

    /**
     * Lines added at once, `{"entities": [line, …]}`, each line as `create`
     * takes it: every line is checked before any is added.
     */
    #[Route(path: 'import', name: self::ROUTE_IMPORT, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: CreateInvoiceItemCollectionDto::class, attributeName: 'createInvoiceItemCollectionDto')]
    #[ApiResponseData(InvoiceItemDto::class, collection: true)]
    public function import(
        CreateInvoiceItemCollectionDto $createInvoiceItemCollectionDto,
        InvoiceRepository $repository,
        InvoiceFactory $factory,
        EntityManagerInterface $entityManager,
        DefaultInvoiceItemNormalizer $normalizer,
    ): ApiResponse {
        $items = $this->withinTheRules(fn () => array_map(
            fn (CreateInvoiceItemDto $line) => $this->addLine($this->reachable($repository->find($line->invoice), LedgerPermission::EDIT), $line, $factory),
            $createInvoiceItemCollectionDto->getCollectionItems()
        ));

        $entityManager->flush();

        return self::apiResponseCollection($normalizer->normalizeCollection($items));
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
    #[ApiResponseData(InvoiceItemDto::class)]
    public function create(
        CreateInvoiceItemDto $createInvoiceItemDto,
        InvoiceRepository $repository,
        InvoiceFactory $factory,
        EntityManagerInterface $entityManager,
        DefaultInvoiceItemNormalizer $normalizer,
    ): ApiResponse {
        $invoice = $this->reachable($repository->find($createInvoiceItemDto->invoice), LedgerPermission::EDIT);
        $item = $this->withinTheRules(fn () => $this->addLine($invoice, $createInvoiceItemDto, $factory));

        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($item));
    }

    /**
     * A line changed in place, keeping its position; only the fields sent change.
     */
    #[Route(path: 'update/{id}', name: self::ROUTE_UPDATE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: InvoiceItemInputDto::class, attributeName: 'invoiceItemInputDto')]
    #[ApiResponseData(InvoiceItemDto::class)]
    public function update(
        string $id,
        Request $request,
        InvoiceItemInputDto $invoiceItemInputDto,
        InvoiceFactory $factory,
        EntityManagerInterface $entityManager,
        DefaultInvoiceItemNormalizer $normalizer,
    ): ApiResponse {
        $item = $this->editableItem($id, $entityManager);
        $invoice = $item->getInvoice();

        $required = fn (mixed $value, string $field): mixed => $value ?? throw new BadRequestHttpException(sprintf('The field "%s" cannot be null.', $field));

        foreach (self::sentFields($request) as $field) {
            match ($field) {
                'title' => $item->setTitle($required($invoiceItemInputDto->title, $field)),
                'description' => $item->setDescription($invoiceItemInputDto->description),
                'reference' => $item->setReference($invoiceItemInputDto->reference),
                'priceRaw' => $item->setPriceRaw($required($invoiceItemInputDto->priceRaw, $field)),
                'quantity' => $item->setQuantity($required($invoiceItemInputDto->quantity, $field)),
                'unit' => $item->setUnit($invoiceItemInputDto->unit),
                'priceVat' => $item->setPriceVat($invoiceItemInputDto->priceVat ?? $factory->defaultVatRate($invoice->getLedger())),
                'goods' => $item->setGoods($required($invoiceItemInputDto->goods, $field)),
                'domesticReverseCharge' => $item->setDomesticReverseCharge($required($invoiceItemInputDto->domesticReverseCharge, $field)),
                default => null,
            };
        }

        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($item));
    }

    #[Route(path: 'delete/{id}', name: self::ROUTE_DELETE, methods: [Request::METHOD_DELETE], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    public function delete(
        string $id,
        EntityManagerInterface $entityManager,
    ): ApiResponse {
        $item = $this->editableItem($id, $entityManager);

        $item->getInvoice()->getItems()->removeElement($item);
        $entityManager->flush();

        return self::apiResponseSuccess();
    }

    private function addLine(
        Invoice $invoice,
        CreateInvoiceItemDto $line,
        InvoiceFactory $factory,
    ): InvoiceItem {
        return $factory->addItem($invoice, $line->title, $line->priceRaw, $line->quantity, $line->priceVat, $line->goods)
            ->setDescription($line->description)
            ->setReference($line->reference)
            ->setUnit($line->unit)
            ->setDomesticReverseCharge($line->domesticReverseCharge);
    }

    private function editableItem(
        string $id,
        EntityManagerInterface $entityManager,
    ): InvoiceItem {
        $item = $entityManager->find(InvoiceItem::class, $id) ?? throw new NotFoundHttpException();

        if (! $this->reachable($item->getInvoice(), LedgerPermission::EDIT)->isEditable()) {
            throw new BadRequestHttpException('An emitted document cannot change: issue a credit note.');
        }

        return $item;
    }
}
