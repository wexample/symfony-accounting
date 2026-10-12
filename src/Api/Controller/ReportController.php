<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Entity\EntryLine;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Service\Exchange\LedgerExportService;
use Wexample\SymfonyAccounting\Service\Report\FinancialStatementService;
use Wexample\SymfonyAccounting\Service\Report\GeneralLedgerService;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;

/**
 * What a fiscal year gives to read: the statements of its jurisdiction
 * (balance sheet, income statement…), its general ledger, its exports (FEC…).
 * Reports are computed, not entities: their answers are plain data.
 */
#[Route(path: 'api/accounting-report/', name: 'api_accounting_report_')]
class ReportController extends AbstractAccountingApiController
{
    final public const string QUERY_OPTION_FISCAL_YEAR = 'fiscalYear';

    #[Route(path: 'statement', name: 'statement', methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_FISCAL_YEAR, required: true)]
    #[StringQueryOption(key: 'key', required: true)]
    public function statement(
        Request $request,
        FiscalYearRepository $repository,
        FinancialStatementService $statementService,
    ): ApiResponse {
        $fiscalYear = $this->fiscalYear($request, $repository);
        $key = (string) self::getQueryOptionValue($request, 'key', '');

        return self::apiResponseSuccess(data: $this->withinTheRules(fn () => $statementService->build($fiscalYear, $key))->toArray());
    }

    /**
     * Every account with its lines and running balance; `account` keeps the
     * accounts starting with it.
     */
    #[Route(path: 'general-ledger', name: 'general_ledger', methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_FISCAL_YEAR, required: true)]
    #[StringQueryOption(key: 'account', default: '')]
    public function generalLedger(
        Request $request,
        FiscalYearRepository $repository,
        GeneralLedgerService $generalLedgerService,
    ): ApiResponse {
        $fiscalYear = $this->fiscalYear($request, $repository);
        $accounts = $generalLedgerService->build(
            $fiscalYear->getLedger(),
            $fiscalYear,
            accountPrefix: (string) self::getQueryOptionValue($request, 'account', '') ?: null,
        );

        return self::apiResponseSuccess(data: array_values(array_map(fn (array $account) => [
            ...$account,
            'lines' => array_map(fn (array $row) => $this->describeLine($row['line'], $row['balance']), $account['lines']),
        ], $accounts)));
    }

    /**
     * The exports this year supports (`format`: FEC, CSV…), or one of them as a file.
     */
    #[Route(path: 'export', name: 'export', methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_FISCAL_YEAR, required: true)]
    #[StringQueryOption(key: 'format', default: '')]
    public function export(
        Request $request,
        FiscalYearRepository $repository,
        LedgerExportService $exportService,
    ): ApiResponse|Response {
        $fiscalYear = $this->fiscalYear($request, $repository);
        $format = (string) self::getQueryOptionValue($request, 'format', '');

        if ('' === $format) {
            return self::apiResponseSuccess(data: $exportService->getChoices($fiscalYear));
        }

        $file = $this->withinTheRules(fn () => $exportService->export($fiscalYear, $format));

        return new Response($file->content, Response::HTTP_OK, [
            'Content-Type' => $file->mimeType,
            'Content-Disposition' => sprintf('attachment; filename="%s"', $file->filename),
        ]);
    }

    private function fiscalYear(
        Request $request,
        FiscalYearRepository $repository
    ): FiscalYear {
        return $this->reachable($repository->find((string) self::getQueryOptionValue($request, self::QUERY_OPTION_FISCAL_YEAR, '')));
    }

    private function describeLine(
        EntryLine $line,
        int $balance
    ): array {
        $entry = $line->getEntry();

        return [
            'id' => (string) $line->getId(),
            'entry' => (string) $entry->getId(),
            'journal' => $entry->getJournal()->getCode(),
            'date' => $entry->getDate()->format('Y-m-d'),
            'label' => $line->getLabel(),
            'debit' => $line->getDebit(),
            'credit' => $line->getCredit(),
            'balance' => $balance,
            'letter' => $line->getLetter(),
        ];
    }
}
