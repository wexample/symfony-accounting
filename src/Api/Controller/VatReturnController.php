<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Class\VatReturn;
use Wexample\SymfonyAccounting\Class\VatReturnLine;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Service\Vat\VatReturnService;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;

/**
 * The VAT of a period (`from`, `to`): computed to be read, the boxes of the
 * jurisdiction's forms filled, then settled — booked into VAT payable or credit.
 */
#[Route(path: 'api/vat-return/', name: 'api_vat_return_')]
class VatReturnController extends AbstractAccountingApiController
{
    final public const string ROUTE_SETTLE = 'settle';

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

    private function compute(
        Request $request,
        VatReturnService $vatReturnService,
        LedgerPermission $permission = LedgerPermission::VIEW
    ): VatReturn {
        $ledger = $this->resolveLedger($request, $permission);

        [$from, $to] = array_map(function (string $key) use ($request): DateTimeImmutable {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) self::getQueryOptionValue($request, $key, ''));

            return $date ?: throw new BadRequestHttpException(sprintf('"%s" is a date, Y-m-d.', $key));
        }, ['from', 'to']);

        return $this->withinTheRules(fn () => $vatReturnService->compute($ledger, $from, $to));
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
