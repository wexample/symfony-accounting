<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\PartyDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\Party\DefaultPartyNormalizer;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
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
 * The customers and suppliers of a ledger (`role=customer|supplier` to keep one side).
 */
#[Route(path: 'api/party/', name: 'api_party_')]
class PartyController extends AbstractAccountingApiController
{
    final public const string QUERY_OPTION_ROLE = 'role';

    #[Route(path: 'list', name: self::ROUTE_LIST, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[StringQueryOption(key: self::QUERY_OPTION_ROLE, default: '')]
    #[SearchQueryOption]
    #[PageQueryOption]
    #[LengthQueryOption]
    #[SortQueryOption(allowed: ['name'], default: 'name')]
    #[ApiResponseData(PartyDto::class, paginated: true)]
    public function list(
        Request $request,
        PartyRepository $repository,
        DefaultPartyNormalizer $normalizer,
    ): ApiResponse {
        $query = $this->ledgerScope($request, $repository->createQueryBuilder('party'), 'party.ledger');

        match (self::getQueryOptionValue($request, self::QUERY_OPTION_ROLE, '')) {
            'customer' => $query->andWhere('party.customer = true'),
            'supplier' => $query->andWhere('party.supplier = true'),
            default => null,
        };

        if ('' !== $search = trim((string) self::getQueryOptionValue($request, VariableHelper::SEARCH, ''))) {
            $query->andWhere('LOWER(party.name) LIKE :search OR LOWER(party.email) LIKE :search OR party.vatNumber LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return self::apiResponseQueryPage($request, $query, static fn (array $parties) => $normalizer->normalizeCollection($parties));
    }

    #[Route(path: 'show/{id}', name: self::ROUTE_SHOW, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(PartyDto::class)]
    public function show(
        string $id,
        PartyRepository $repository,
        DefaultPartyNormalizer $normalizer,
    ): ApiResponse {
        return self::apiResponseSuccess(data: $normalizer->normalize($this->reachable($repository->find($id))));
    }
}
