<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\BankTransactionDto;
use Wexample\SymfonyAccounting\Api\Dto\LinkTransferDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\BankTransaction\DefaultBankTransactionNormalizer;
use Wexample\SymfonyAccounting\Class\MatchProposal;
use Wexample\SymfonyAccounting\Entity\Allocation;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\BankAccountRepository;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyAccounting\Service\Bank\AllocationService;
use Wexample\SymfonyAccounting\Service\Bank\BankImportService;
use Wexample\SymfonyAccounting\Service\Bank\MatchingService;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\BooleanQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\LengthQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\PageQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\SearchQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\SortQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\YearQueryOption;
use Wexample\SymfonyApi\Api\Attribute\ValidateRequestContent;
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

    final public const string QUERY_OPTION_PARSER = 'parser';

    final public const string ROUTE_IMPORT = 'import';

    final public const string ROUTE_MATCH = 'match';

    final public const string ROUTE_PROPOSALS = 'proposals';

    final public const string ROUTE_LINK_TRANSFER = 'link_transfer';

    final public const string ROUTE_UNLINK_TRANSFER = 'unlink_transfer';

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

    /**
     * A statement file (`file`, multipart) read into the account's lines: its
     * format detected, or named (`parser`); lines already known are skipped.
     */
    #[Route(path: 'import', name: self::ROUTE_IMPORT, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_BANK_ACCOUNT, required: true)]
    #[StringQueryOption(key: self::QUERY_OPTION_PARSER, default: '')]
    public function import(
        Request $request,
        BankAccountRepository $bankAccountRepository,
        BankImportService $importService,
    ): ApiResponse {
        $bankAccount = $this->reachable(
            $bankAccountRepository->find((string) self::getQueryOptionValue($request, self::QUERY_OPTION_BANK_ACCOUNT, '')),
            LedgerPermission::EDIT
        );
        $file = $request->files->get('file');

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw new BadRequestHttpException('Send the statement as the multipart field "file".');
        }

        $result = $this->withinTheRules(fn () => $importService->importContent(
            $bankAccount,
            (string) file_get_contents($file->getPathname()),
            (string) self::getQueryOptionValue($request, self::QUERY_OPTION_PARSER, '') ?: null,
            filename: $file->getClientOriginalName(),
        ));

        return self::apiResponseSuccess(data: [
            'batch' => $result->batch,
            'created' => count($result->created),
            'duplicates' => $result->duplicates,
        ]);
    }

    /**
     * Every line still to explain matched where a proposal is sure enough.
     */
    #[Route(path: 'match', name: self::ROUTE_MATCH, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[StringQueryOption(key: self::QUERY_OPTION_BANK_ACCOUNT, default: '')]
    public function match(
        Request $request,
        BankAccountRepository $bankAccountRepository,
        MatchingService $matchingService,
    ): ApiResponse {
        $ledger = $this->resolveLedger($request, LedgerPermission::EDIT);
        $bankAccountId = (string) self::getQueryOptionValue($request, self::QUERY_OPTION_BANK_ACCOUNT, '');
        $bankAccount = $bankAccountId ? $this->reachable($bankAccountRepository->find($bankAccountId), LedgerPermission::EDIT) : null;

        return self::apiResponseSuccess(data: ['matched' => $this->withinTheRules(fn () => $matchingService->run($ledger, $bankAccount))]);
    }

    /**
     * What the line could pay, best first: a document, an account, the other
     * side of a transfer. Accepting one is an allocation or a transfer link.
     */
    #[Route(path: 'proposals/{id}', name: self::ROUTE_PROPOSALS, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    public function proposals(
        string $id,
        BankTransactionRepository $repository,
        MatchingService $matchingService,
    ): ApiResponse {
        $transaction = $this->reachable($repository->find($id));

        return self::apiResponseSuccess(data: array_map(fn (MatchProposal $proposal) => [
            'matcher' => $proposal->matcher,
            'confidence' => $proposal->confidence,
            'invoice' => $proposal->invoice ? ['id' => (string) $proposal->invoice->getId(), 'number' => $proposal->invoice->getNumber()] : null,
            'accountNumber' => $proposal->accountNumber,
            'transferPeer' => $proposal->transferPeer ? (string) $proposal->transferPeer->getId() : null,
            'amount' => $proposal->amount,
            'label' => $proposal->label,
        ], $matchingService->propose($transaction)));
    }

    #[Route(path: 'link-transfer', name: self::ROUTE_LINK_TRANSFER, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: LinkTransferDto::class, attributeName: 'linkTransferDto')]
    public function linkTransfer(
        LinkTransferDto $linkTransferDto,
        BankTransactionRepository $repository,
        AllocationService $allocationService,
    ): ApiResponse {
        $line = $this->reachable($repository->find($linkTransferDto->transaction), LedgerPermission::EDIT);
        $peer = $this->reachable($repository->find($linkTransferDto->peer), LedgerPermission::EDIT);
        $this->withinTheRules(fn () => $allocationService->linkTransfer($line, $peer));

        return self::apiResponseSuccess();
    }

    #[Route(path: 'unlink-transfer/{id}', name: self::ROUTE_UNLINK_TRANSFER, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    public function unlinkTransfer(
        string $id,
        BankTransactionRepository $repository,
        AllocationService $allocationService,
    ): ApiResponse {
        $allocationService->unlinkTransfer($this->reachable($repository->find($id), LedgerPermission::EDIT));

        return self::apiResponseSuccess();
    }
}
