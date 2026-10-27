<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\FiscalYearDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\FiscalYear\DefaultFiscalYearNormalizer;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearClosingService;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearService;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\BooleanQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyCheck\Class\Finding;
use Wexample\SymfonyHelpers\Controller\AbstractController;

/**
 * The fiscal years of a ledger: listed, checked before closing, closed,
 * reopened. Closing and reopening ask `accounting.administer`.
 */
#[Route(path: '_api/fiscal-year/', name: 'api_fiscal_year_')]
class FiscalYearController extends AbstractAccountingApiController
{
    final public const string ROUTE_CHECK = 'check';

    final public const string ROUTE_CLOSE = 'close';

    final public const string ROUTE_REOPEN = 'reopen';

    final public const string ROUTE_NEXT = 'next';

    #[Route(path: 'list', name: self::ROUTE_LIST, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[ApiResponseData(FiscalYearDto::class, collection: true)]
    public function list(
        Request $request,
        FiscalYearRepository $repository,
        DefaultFiscalYearNormalizer $normalizer,
    ): ApiResponse {
        return self::apiResponseCollection($normalizer->normalizeCollection($repository->findByLedger($this->resolveLedger($request))));
    }

    /**
     * The year after the last one, opened.
     */
    #[Route(path: 'next', name: self::ROUTE_NEXT, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[ApiResponseData(FiscalYearDto::class)]
    public function next(
        Request $request,
        FiscalYearService $fiscalYearService,
        DefaultFiscalYearNormalizer $normalizer,
    ): ApiResponse {
        $ledger = $this->resolveLedger($request, LedgerPermission::ADMINISTER);

        return self::apiResponseSuccess(data: $normalizer->normalize($this->withinTheRules(fn () => $fiscalYearService->createNext($ledger))));
    }

    /**
     * What stands in the way of closing, or should be looked at first.
     */
    #[Route(path: 'check/{id}', name: self::ROUTE_CHECK, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    public function check(
        string $id,
        FiscalYearRepository $repository,
        FiscalYearClosingService $closingService,
    ): ApiResponse {
        $fiscalYear = $this->reachable($repository->find($id));
        $report = $closingService->check($fiscalYear);

        return self::apiResponseSuccess(data: [
            'result' => $closingService->computeResult($fiscalYear),
            'findings' => array_map(fn (Finding $finding) => $finding->toArray(), $report->getFindings()),
        ]);
    }

    /**
     * Closed with its result carried forward; `force` closes despite warnings.
     */
    #[Route(path: 'close/{id}', name: self::ROUTE_CLOSE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[BooleanQueryOption(key: 'force', default: false)]
    #[ApiResponseData(FiscalYearDto::class)]
    public function close(
        string $id,
        Request $request,
        FiscalYearRepository $repository,
        FiscalYearClosingService $closingService,
        DefaultFiscalYearNormalizer $normalizer,
    ): ApiResponse {
        $fiscalYear = $this->reachable($repository->find($id), LedgerPermission::ADMINISTER);
        $force = (bool) self::getQueryOptionValue($request, 'force', false);

        return self::apiResponseSuccess(data: $normalizer->normalize($this->withinTheRules(fn () => $closingService->close($fiscalYear, $force))));
    }

    #[Route(path: 'reopen/{id}', name: self::ROUTE_REOPEN, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(FiscalYearDto::class)]
    public function reopen(
        string $id,
        FiscalYearRepository $repository,
        FiscalYearClosingService $closingService,
        DefaultFiscalYearNormalizer $normalizer,
    ): ApiResponse {
        $fiscalYear = $this->reachable($repository->find($id), LedgerPermission::ADMINISTER);

        return self::apiResponseSuccess(data: $normalizer->normalize($this->withinTheRules(fn () => $closingService->reopen($fiscalYear))));
    }
}
