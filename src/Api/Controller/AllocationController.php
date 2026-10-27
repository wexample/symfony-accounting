<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\AllocationDto;
use Wexample\SymfonyAccounting\Api\Dto\CreateAllocationDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\Allocation\DefaultAllocationNormalizer;
use Wexample\SymfonyAccounting\Entity\Allocation;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Service\Bank\AllocationService;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyApi\Api\Attribute\ValidateRequestContent;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;

/**
 * What a bank line pays. Allocating books the payment and moves the document
 * towards paid; removing keeps the allocation, excluded, and reverses its entry.
 */
#[Route(path: '_api/allocation/', name: 'api_allocation_')]
class AllocationController extends AbstractAccountingApiController
{
    final public const string QUERY_OPTION_TRANSACTION = 'transaction';

    #[Route(path: 'list', name: self::ROUTE_LIST, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_TRANSACTION, required: true)]
    #[ApiResponseData(AllocationDto::class, collection: true)]
    public function list(
        Request $request,
        BankTransactionRepository $repository,
        DefaultAllocationNormalizer $normalizer,
    ): ApiResponse {
        $transaction = $this->reachable($repository->find((string) self::getQueryOptionValue($request, self::QUERY_OPTION_TRANSACTION, '')));

        return self::apiResponseCollection($normalizer->normalizeCollection($transaction->getAllocations()->toArray()));
    }

    #[Route(path: 'create', name: self::ROUTE_CREATE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: CreateAllocationDto::class, attributeName: 'createAllocationDto')]
    #[ApiResponseData(AllocationDto::class)]
    public function create(
        CreateAllocationDto $createAllocationDto,
        BankTransactionRepository $transactionRepository,
        InvoiceRepository $invoiceRepository,
        AllocationService $allocationService,
        EntityManagerInterface $entityManager,
        DefaultAllocationNormalizer $normalizer,
    ): ApiResponse {
        $transaction = $this->reachable($transactionRepository->find($createAllocationDto->transaction), LedgerPermission::EDIT);
        $invoice = $this->reachable($invoiceRepository->find($createAllocationDto->invoice), LedgerPermission::EDIT);

        $allocation = $this->withinTheRules(fn () => $allocationService->allocate($transaction, $invoice, $createAllocationDto->amount));
        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($allocation));
    }

    #[Route(path: 'delete/{id}', name: self::ROUTE_DELETE, methods: [Request::METHOD_DELETE], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(AllocationDto::class)]
    public function delete(
        string $id,
        AllocationService $allocationService,
        EntityManagerInterface $entityManager,
        DefaultAllocationNormalizer $normalizer,
    ): ApiResponse {
        $allocation = $entityManager->find(Allocation::class, $id) ?? throw new NotFoundHttpException();
        $this->reachable($allocation->getTransaction(), LedgerPermission::EDIT);

        $this->withinTheRules(fn () => $allocationService->exclude($allocation));
        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($allocation));
    }
}
