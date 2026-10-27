<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\AccountDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\Account\DefaultAccountNormalizer;
use Wexample\SymfonyAccounting\Repository\AccountRepository;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\LengthQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\PageQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\SearchQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\SortQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;
use Wexample\SymfonyHelpers\Helper\VariableHelper;

/**
 * The chart of a ledger: by number, or words of the label (`search` starting
 * with a digit reads as the start of a number).
 */
#[Route(path: '_api/account/', name: 'api_account_')]
class AccountController extends AbstractAccountingApiController
{
    #[Route(path: 'list', name: self::ROUTE_LIST, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[SearchQueryOption]
    #[PageQueryOption]
    #[LengthQueryOption]
    #[SortQueryOption(allowed: ['number'], default: 'number')]
    #[ApiResponseData(AccountDto::class, paginated: true)]
    public function list(
        Request $request,
        AccountRepository $repository,
        DefaultAccountNormalizer $normalizer,
    ): ApiResponse {
        $query = $this->ledgerScope($request, $repository->createQueryBuilder('account'), 'account.ledger');

        $search = trim((string) self::getQueryOptionValue($request, VariableHelper::SEARCH, ''));
        if (ctype_digit(substr($search, 0, 1))) {
            $query->andWhere('account.number LIKE :number')->setParameter('number', $search.'%');
        } elseif ('' !== $search) {
            $query->andWhere('LOWER(account.label) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return self::apiResponseQueryPage($request, $query, static fn (array $accounts) => $normalizer->normalizeCollection($accounts));
    }
}
