<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\BankTransactionDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\BankTransaction\DefaultBankTransactionNormalizer;
use Wexample\SymfonyAccounting\Entity\Allocation;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\BooleanQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\LengthQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\PageQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\SearchQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\SortQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\YearQueryOption;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * The bank lines of a ledger, of one account (`bankAccount`) or all, those
 * still to explain only (`unallocated`) — what reconciliation works through.
 */
#[Route(path: 'api/bank-transaction/', name: 'api_bank_transaction_')]
class BankTransactionController extends AbstractAccountingApiController
{
    final public const string QUERY_OPTION_BANK_ACCOUNT = 'bankAccount';

    final public const string QUERY_OPTION_UNALLOCATED = 'unallocated';

    #[Route(path: 'list', name: self::ROUTE_LIST, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[StringQueryOption(key: self::QUERY_OPTION_BANK_ACCOUNT, default: '')]
    #[BooleanQueryOption(key: self::QUERY_OPTION_UNALLOCATED, default: false)]
    #[YearQueryOption]
    #[SearchQueryOption]
    #[PageQueryOption]
    #[LengthQueryOption]
    #[SortQueryOption(allowed: ['date', 'amount'], default: '-date')]
    #[ApiResponseData(BankTransactionDto::class, paginated: true)]
    public function list(
        Request $request,
        BankTransactionRepository $repository,
        DefaultBankTransactionNormalizer $normalizer,
    ): ApiResponse {
        $query = $this->ledgerScope($request, $repository->createQueryBuilder('line')->join('line.bankAccount', 'bankAccount'), 'bankAccount.ledger');

        if ($bankAccount = (string) self::getQueryOptionValue($request, self::QUERY_OPTION_BANK_ACCOUNT, '')) {
            $query->andWhere('bankAccount.id = :bankAccount')->setParameter('bankAccount', $bankAccount, UuidType::NAME);
        }

        if (self::getQueryOptionValue($request, self::QUERY_OPTION_UNALLOCATED, false)) {
            // Active allocations covering less than the line: what the line still waits for.
            $query->andWhere('line.transferPeer IS NULL')
                ->andWhere('ABS(line.amount) > (SELECT COALESCE(SUM(allocation.amount), 0) FROM '.Allocation::class.' allocation WHERE allocation.transaction = line AND allocation.status <> :excluded)')
                ->setParameter('excluded', AllocationStatus::Excluded);
        }

        if ('' !== $search = trim((string) self::getQueryOptionValue($request, VariableHelper::SEARCH, ''))) {
            $query->andWhere('LOWER(line.label) LIKE :search OR LOWER(line.counterpartyName) LIKE :search OR line.reference LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        self::applyQueryOptionYear($request, $query, 'line.date');

        return self::apiResponseQueryPage($request, $query, static fn (array $lines) => $normalizer->normalizeCollection($lines));
    }
}
