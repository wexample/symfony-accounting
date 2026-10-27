<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\PhpDate\Helper\DateHelper;
use Wexample\SymfonyAccounting\Class\TrialBalanceRow;
use Wexample\SymfonyAccounting\Service\Report\TrialBalanceService;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\YearQueryOption;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * The balance of every account over a calendar year: a report, not an entity.
 */
#[Route(path: '_api/trial-balance/', name: 'api_trial_balance_')]
class TrialBalanceController extends AbstractAccountingApiController
{
    #[Route(path: 'show', name: self::ROUTE_SHOW, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[YearQueryOption]
    public function show(
        Request $request,
        TrialBalanceService $trialBalanceService,
    ): ApiResponse {
        $year = (int) self::getQueryOptionValue($request, VariableHelper::YEAR, DateHelper::getCurrentYearInt());
        $balance = $trialBalanceService->build(
            $this->resolveLedger($request),
            from: new DateTimeImmutable("$year-01-01"),
            to: new DateTimeImmutable("$year-12-31"),
        );

        return self::apiResponseSuccess(data: [
            'year' => $year,
            'rows' => array_values(array_map(fn (TrialBalanceRow $row) => [
                'account' => $row->number,
                'label' => $row->label,
                'debit' => $row->debit,
                'credit' => $row->credit,
            ], $balance->rows)),
            'totalDebit' => $balance->getTotalDebit(),
            'totalCredit' => $balance->getTotalCredit(),
            'balanced' => $balance->isBalanced(),
        ]);
    }
}
