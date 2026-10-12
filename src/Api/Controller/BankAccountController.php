<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\BankAccountDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\BankAccount\DefaultBankAccountNormalizer;
use Wexample\SymfonyAccounting\Repository\BankAccountRepository;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;

/**
 * The bank accounts of a ledger.
 */
#[Route(path: 'api/bank-account/', name: 'api_bank_account_')]
class BankAccountController extends AbstractAccountingApiController
{
    #[Route(path: 'list', name: self::ROUTE_LIST, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[ApiResponseData(BankAccountDto::class, collection: true)]
    public function list(
        Request $request,
        BankAccountRepository $repository,
        DefaultBankAccountNormalizer $normalizer,
    ): ApiResponse {
        return self::apiResponseCollection($normalizer->normalizeCollection(
            $repository->findBy(['ledger' => $this->resolveLedger($request)], ['label' => 'ASC'])
        ));
    }
}
