<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\CreateInvoiceItemDto;
use Wexample\SymfonyAccounting\Api\Dto\InvoiceDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\Invoice\DefaultInvoiceNormalizer;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
use Wexample\SymfonyApi\Api\Attribute\ValidateRequestContent;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;

/**
 * The lines of a draft. Each change answers with the whole document, its
 * totals computed again.
 */
#[Route(path: 'api/invoice-item/', name: 'api_invoice_item_')]
class InvoiceItemController extends AbstractAccountingApiController
{
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
        )->setDescription($createInvoiceItemDto->description));

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
