<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\JournalEntryDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\JournalEntry\DefaultJournalEntryNormalizer;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\LengthQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\PageQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\SortQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\YearQueryOption;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;

/**
 * The entries of a ledger for a year, of one journal (`journal`, its code) or all.
 */
#[Route(path: 'api/journal-entry/', name: 'api_journal_entry_')]
class JournalEntryController extends AbstractAccountingApiController
{
    final public const string QUERY_OPTION_JOURNAL = 'journal';

    #[Route(path: 'list', name: self::ROUTE_LIST, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[StringQueryOption(key: self::QUERY_OPTION_JOURNAL, default: '')]
    #[YearQueryOption]
    #[PageQueryOption]
    #[LengthQueryOption]
    #[SortQueryOption(allowed: ['date', 'number'], default: 'date')]
    #[ApiResponseData(JournalEntryDto::class, paginated: true)]
    public function list(
        Request $request,
        JournalEntryRepository $repository,
        DefaultJournalEntryNormalizer $normalizer,
    ): ApiResponse {
        $query = $this->ledgerScope($request, $repository->createQueryBuilder('entry')->join('entry.journal', 'journal'), 'entry.ledger');

        if ($journal = (string) self::getQueryOptionValue($request, self::QUERY_OPTION_JOURNAL, '')) {
            $query->andWhere('journal.code = :journal')->setParameter('journal', $journal);
        }

        self::applyQueryOptionYear($request, $query, 'entry.date');

        return self::apiResponseQueryPage($request, $query, static fn (array $entries) => $normalizer->normalizeCollection($entries));
    }
}
