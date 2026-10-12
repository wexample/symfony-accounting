<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\LedgerDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\Ledger\DefaultLedgerNormalizer;
use Wexample\SymfonyAccounting\Service\Ledger\LedgerAccessService;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;

/**
 * The books the reader may open.
 */
#[Route(path: 'api/ledger/', name: 'api_ledger_')]
class LedgerController extends AbstractAccountingApiController
{
    #[Route(path: 'list', name: self::ROUTE_LIST, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(LedgerDto::class, collection: true)]
    public function list(
        LedgerAccessService $access,
        DefaultLedgerNormalizer $normalizer,
    ): ApiResponse {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');

        return self::apiResponseCollection($normalizer->normalizeCollection($access->getAccessible()));
    }

    #[Route(path: 'show/{id}', name: self::ROUTE_SHOW, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(LedgerDto::class)]
    public function show(
        string $id,
        DefaultLedgerNormalizer $normalizer,
    ): ApiResponse {
        return self::apiResponseSuccess(data: $normalizer->normalize($this->reachable($this->ledgerRepository->find($id))));
    }
}
