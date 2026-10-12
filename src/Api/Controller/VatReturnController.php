<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\PhpDate\Helper\DateHelper;
use Wexample\SymfonyAccounting\Class\VatReturn;
use Wexample\SymfonyAccounting\Class\VatReturnLine;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Service\Vat\OssService;
use Wexample\SymfonyAccounting\Service\Vat\VatPositionService;
use Wexample\SymfonyAccounting\Service\Vat\VatReturnService;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\YearQueryOption;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * The VAT of a period (`from`, `to`): computed to be read, the boxes of the
 * jurisdiction's forms filled, then settled — booked into VAT payable or credit.
 */
#[Route(path: 'api/vat-return/', name: 'api_vat_return_')]
class VatReturnController extends AbstractAccountingApiController
{
    final public const string ROUTE_SETTLE = 'settle';

    final public const string ROUTE_POSITION = 'position';

    final public const string ROUTE_OSS = 'oss';

    final public const string ROUTE_OSS_SETTLE = 'oss_settle';

    /**
     * Where the year's VAT stands, period by period, deposits paid and expected.
     */
    #[Route(path: 'position', name: self::ROUTE_POSITION, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[YearQueryOption]
    public function position(
        Request $request,
        VatPositionService $positionService,
    ): ApiResponse {
        return self::apiResponseSuccess(data: $positionService->build(
            $this->resolveLedger($request),
            (int) self::getQueryOptionValue($request, VariableHelper::YEAR, DateHelper::getCurrentYearInt()),
        )->toArray());
    }

    #[Route(path: 'show', name: self::ROUTE_SHOW, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[StringQueryOption(key: 'from', required: true)]
    #[StringQueryOption(key: 'to', required: true)]
    public function show(
        Request $request,
        VatReturnService $vatReturnService,
    ): ApiResponse {
        $vatReturn = $this->compute($request, $vatReturnService);

        return self::apiResponseSuccess(data: [
            ...$this->describe($vatReturn),
            'forms' => $vatReturnService->fillForms($vatReturn),
        ]);
    }

    #[Route(path: 'settle', name: self::ROUTE_SETTLE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[StringQueryOption(key: 'from', required: true)]
    #[StringQueryOption(key: 'to', required: true)]
    public function settle(
        Request $request,
        VatReturnService $vatReturnService,
    ): ApiResponse {
        $vatReturn = $this->compute($request, $vatReturnService, LedgerPermission::EDIT);
        $entry = $this->withinTheRules(fn () => $vatReturnService->settle($vatReturn));

        return self::apiResponseSuccess(data: [...$this->describe($vatReturn), 'entry' => (string) $entry->getId()]);
    }

    /**
     * The One-Stop Shop return of a period: base and VAT by country and rate, the
     * year's distance sales against the threshold, and the CSV for the portal.
     */
    #[Route(path: 'oss', name: self::ROUTE_OSS, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[StringQueryOption(key: 'from', required: true)]
    #[StringQueryOption(key: 'to', required: true)]
    public function oss(
        Request $request,
        OssService $ossService,
    ): ApiResponse {
        $ledger = $this->resolveLedger($request);
        [$from, $to] = $this->period($request);
        $lines = $ossService->computeReturn($ledger, $from, $to);

        return self::apiResponseSuccess(data: [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'lines' => $lines,
            'distanceSales' => $ossService->getDistanceSales($ledger, (int) $from->format('Y')),
            'threshold' => OssService::THRESHOLD,
            'csv' => $ossService->exportCsv($ledger, $lines),
        ]);
    }

    #[Route(path: 'oss-settle', name: self::ROUTE_OSS_SETTLE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[StringQueryOption(key: 'from', required: true)]
    #[StringQueryOption(key: 'to', required: true)]
    public function ossSettle(
        Request $request,
        OssService $ossService,
    ): ApiResponse {
        $ledger = $this->resolveLedger($request, LedgerPermission::EDIT);
        [$from, $to] = $this->period($request);
        $entry = $this->withinTheRules(fn () => $ossService->settle($ledger, $from, $to));

        return self::apiResponseSuccess(data: ['entry' => (string) $entry->getId()]);
    }

    private function compute(
        Request $request,
        VatReturnService $vatReturnService,
        LedgerPermission $permission = LedgerPermission::VIEW
    ): VatReturn {
        $ledger = $this->resolveLedger($request, $permission);
        [$from, $to] = $this->period($request);

        return $this->withinTheRules(fn () => $vatReturnService->compute($ledger, $from, $to));
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function period(Request $request): array
    {
        return array_map(function (string $key) use ($request): DateTimeImmutable {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) self::getQueryOptionValue($request, $key, ''));

            return $date ?: throw new BadRequestHttpException(sprintf('"%s" is a date, Y-m-d.', $key));
        }, ['from', 'to']);
    }

    private function describe(VatReturn $vatReturn): array
    {
        return [
            'from' => $vatReturn->from->format('Y-m-d'),
            'to' => $vatReturn->to->format('Y-m-d'),
            'due' => $vatReturn->getDue(),
            'deductible' => $vatReturn->getDeductible(),
            'previousCredit' => $vatReturn->previousCredit,
            'deposits' => $vatReturn->deposits,
            'net' => $vatReturn->getNet(),
            'lines' => array_values(array_map(fn (VatReturnLine $line) => [
                'code' => $line->code,
                'direction' => $line->direction?->value,
                'base' => $line->base,
                'due' => $line->due,
                'deductible' => $line->deductible,
            ], $vatReturn->lines)),
        ];
    }
}
