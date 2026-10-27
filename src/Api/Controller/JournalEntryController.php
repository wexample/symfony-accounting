<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\CreateJournalEntryDto;
use Wexample\SymfonyAccounting\Api\Dto\JournalEntryDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\JournalEntry\DefaultJournalEntryNormalizer;
use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
use Wexample\SymfonyAccounting\Service\Ledger\PostingService;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\LengthQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\PageQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\SortQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\YearQueryOption;
use Wexample\SymfonyApi\Api\Attribute\ValidateRequestContent;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyHelpers\Controller\AbstractController;

/**
 * The entries of a ledger for a year, of one journal (`journal`, its code) or all.
 */
#[Route(path: '_api/journal-entry/', name: 'api_journal_entry_')]
class JournalEntryController extends AbstractAccountingApiController
{
    final public const string QUERY_OPTION_JOURNAL = 'journal';

    final public const string ROUTE_REVERSE = 'reverse';

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

    /**
     * Booked at once if it balances, in the open fiscal year of its date.
     */
    #[Route(path: 'create', name: self::ROUTE_CREATE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: CreateJournalEntryDto::class, attributeName: 'createJournalEntryDto')]
    #[ApiResponseData(JournalEntryDto::class)]
    public function create(
        CreateJournalEntryDto $createJournalEntryDto,
        PartyRepository $partyRepository,
        PostingService $postingService,
        DefaultJournalEntryNormalizer $normalizer,
    ): ApiResponse {
        $input = $createJournalEntryDto;
        $ledger = $this->reachable($this->ledgerRepository->find($input->ledger), LedgerPermission::EDIT);
        $draft = (new EntryDraft($input->journal, new DateTimeImmutable($input->date), $input->label))->piece($input->pieceReference);

        foreach ($input->lines as $line) {
            $party = null;
            if ($line['party'] ?? null) {
                $party = $partyRepository->find($line['party']);
                if (! $party || $party->getLedger() !== $ledger) {
                    throw new BadRequestHttpException('A line names a party of other books.');
                }
            }

            if (! is_string($line['account'] ?? null)) {
                throw new BadRequestHttpException('Each line names its account.');
            }

            $draft->add($line['account'], (int) ($line['debit'] ?? 0) - (int) ($line['credit'] ?? 0), $line['label'] ?? null, $party);
        }

        return self::apiResponseSuccess(data: $normalizer->normalize(
            $this->withinTheRules(fn () => $postingService->postDraft($ledger, $draft))
        ));
    }

    /**
     * The entry cancelled by its mirror, dated today unless `date` is given: a booked entry is never edited.
     */
    #[Route(path: 'reverse/{id}', name: self::ROUTE_REVERSE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: 'date', default: '')]
    #[ApiResponseData(JournalEntryDto::class)]
    public function reverse(
        string $id,
        Request $request,
        JournalEntryRepository $repository,
        PostingService $postingService,
        DefaultJournalEntryNormalizer $normalizer,
    ): ApiResponse {
        $entry = $this->reachable($repository->find($id), LedgerPermission::EDIT);
        $date = (string) self::getQueryOptionValue($request, 'date', '');

        return self::apiResponseSuccess(data: $normalizer->normalize(
            $this->withinTheRules(fn () => $postingService->reverse($entry, $date ? new DateTimeImmutable($date) : null))
        ));
    }
}
