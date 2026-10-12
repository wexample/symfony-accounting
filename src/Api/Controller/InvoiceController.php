<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\CreateCreditNoteDto;
use Wexample\SymfonyAccounting\Api\Dto\CreateInvoiceDto;
use Wexample\SymfonyAccounting\Api\Dto\InvoiceDto;
use Wexample\SymfonyAccounting\Api\Dto\InvoiceInputDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\Invoice\DefaultInvoiceNormalizer;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
use Wexample\SymfonyAccounting\Service\Document\InvoicePdfService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
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
 * The documents of a ledger: listed, read, drafted, emitted, cancelled by a
 * credit note, printed. Reading asks `accounting.view`, the rest `accounting.edit`.
 */
#[Route(path: 'api/invoice/', name: 'api_invoice_')]
class InvoiceController extends AbstractAccountingApiController
{
    final public const string ROUTE_EMIT = 'emit';

    final public const string ROUTE_CREDIT_NOTE = 'credit_note';

    final public const string ROUTE_PDF = 'pdf';

    final public const string ROUTE_BILL = 'bill';

    final public const string ROUTE_DUPLICATE = 'duplicate';

    final public const string QUERY_OPTION_TYPE = 'type';

    final public const string QUERY_OPTION_STATUS = 'status';

    final public const string QUERY_OPTION_DIRECTION = 'direction';

    /**
     * Narrowed by type, status (several, comma separated), direction, year of
     * the document and words of its number, title or party.
     */
    #[Route(path: 'list', name: self::ROUTE_LIST, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    #[StringQueryOption(key: self::QUERY_OPTION_TYPE, default: '')]
    #[StringQueryOption(key: self::QUERY_OPTION_STATUS, default: '')]
    #[StringQueryOption(key: self::QUERY_OPTION_DIRECTION, default: '')]
    #[YearQueryOption]
    #[SearchQueryOption]
    #[PageQueryOption]
    #[LengthQueryOption]
    #[SortQueryOption(allowed: ['dateInvoice', 'number', 'status'], default: '-dateInvoice', tieBreaker: 'id')]
    #[ApiResponseData(InvoiceDto::class, paginated: true)]
    public function list(
        Request $request,
        InvoiceRepository $repository,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        $query = $this->ledgerScope($request, $repository->createQueryBuilder('invoice')->leftJoin('invoice.party', 'party'), 'invoice.ledger');

        foreach ([self::QUERY_OPTION_TYPE => 'type', self::QUERY_OPTION_STATUS => 'status', self::QUERY_OPTION_DIRECTION => 'direction'] as $option => $field) {
            if ($values = array_values(array_filter(explode(',', (string) self::getQueryOptionValue($request, $option, ''))))) {
                $query->andWhere("invoice.$field IN (:$field)")->setParameter($field, $values);
            }
        }

        if ('' !== $search = trim((string) self::getQueryOptionValue($request, VariableHelper::SEARCH, ''))) {
            $query->andWhere('LOWER(invoice.number) LIKE :search OR LOWER(invoice.title) LIKE :search OR LOWER(party.name) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        self::applyQueryOptionYear($request, $query, 'invoice.dateInvoice');

        return self::apiResponseQueryPage($request, $query, static fn (array $invoices) => $normalizer->normalizeCollection($invoices));
    }

    #[Route(path: 'show/{id}', name: self::ROUTE_SHOW, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(InvoiceDto::class)]
    public function show(
        string $id,
        InvoiceRepository $repository,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        return self::apiResponseSuccess(data: $normalizer->normalize($this->reachable($repository->find($id))));
    }

    /**
     * The document as a PDF, in the language asked (`?locale=`), the request's otherwise.
     */
    #[Route(path: 'pdf/{id}', name: self::ROUTE_PDF, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: 'locale', default: '')]
    public function pdf(
        string $id,
        Request $request,
        InvoiceRepository $repository,
        InvoicePdfService $pdfService,
    ): Response {
        $invoice = $this->reachable($repository->find($id));
        $locale = (string) self::getQueryOptionValue($request, 'locale', '') ?: $request->getLocale();

        return new Response($pdfService->render($invoice, $locale), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s.pdf"', $invoice->getNumber() ?? 'draft-'.$invoice->getId()),
        ]);
    }

    #[Route(path: 'create', name: self::ROUTE_CREATE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: CreateInvoiceDto::class, attributeName: 'createInvoiceDto')]
    #[ApiResponseData(InvoiceDto::class)]
    public function create(
        CreateInvoiceDto $createInvoiceDto,
        PartyRepository $partyRepository,
        InvoiceFactory $factory,
        EntityManagerInterface $entityManager,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        $ledger = $this->reachable($this->ledgerRepository->find($createInvoiceDto->ledger), LedgerPermission::EDIT);
        $party = $createInvoiceDto->party ? $this->partyOf($ledger, $createInvoiceDto->party, $partyRepository) : null;

        $invoice = $this->withinTheRules(fn () => $factory->create(
            $ledger,
            InvoiceType::tryFrom($createInvoiceDto->type) ?? throw new BadRequestHttpException('Unknown document type.'),
            InvoiceDirection::tryFrom($createInvoiceDto->direction) ?? throw new BadRequestHttpException('Unknown direction.'),
            $party,
            $createInvoiceDto->dateInvoice ? new DateTimeImmutable($createInvoiceDto->dateInvoice) : null,
            $createInvoiceDto->title,
        )->setNote($createInvoiceDto->note));

        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($invoice));
    }

    #[Route(path: 'update/{id}', name: self::ROUTE_UPDATE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: InvoiceInputDto::class, attributeName: 'invoiceInputDto')]
    #[ApiResponseData(InvoiceDto::class)]
    public function update(
        string $id,
        Request $request,
        InvoiceInputDto $invoiceInputDto,
        InvoiceRepository $repository,
        PartyRepository $partyRepository,
        EntityManagerInterface $entityManager,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        $invoice = $this->reachable($repository->find($id), LedgerPermission::EDIT);

        if (! $invoice->isEditable()) {
            throw new BadRequestHttpException('An emitted document cannot change: issue a credit note.');
        }

        foreach (self::sentFields($request) as $field) {
            match ($field) {
                'party' => $invoice->setParty($invoiceInputDto->party ? $this->partyOf($invoice->getLedger(), $invoiceInputDto->party, $partyRepository) : null),
                'title' => $invoice->setTitle($invoiceInputDto->title),
                'note' => $invoice->setNote($invoiceInputDto->note),
                'dateInvoice' => $invoice->setDateInvoice(new DateTimeImmutable((string) $invoiceInputDto->dateInvoice)),
                'dateValidUntil' => $invoice->setDateValidUntil($invoiceInputDto->dateValidUntil ? new DateTimeImmutable($invoiceInputDto->dateValidUntil) : null),
                'paymentTermDays' => $invoice->setPaymentTermDays($invoiceInputDto->paymentTermDays),
                default => null,
            };
        }

        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($invoice));
    }

    /**
     * The bill of an accepted quotation, as a draft: its items, its deposits deducted.
     */
    #[Route(path: 'bill/{id}', name: self::ROUTE_BILL, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(InvoiceDto::class)]
    public function bill(
        string $id,
        InvoiceRepository $repository,
        InvoiceFactory $factory,
        EntityManagerInterface $entityManager,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        $quotation = $this->reachable($repository->find($id), LedgerPermission::EDIT);
        $bill = $this->withinTheRules(fn () => $factory->createBillFromQuotation($quotation));
        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($bill));
    }

    /**
     * A new draft with the same content and items: never the number, the dates nor the payments.
     */
    #[Route(path: 'duplicate/{id}', name: self::ROUTE_DUPLICATE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(InvoiceDto::class)]
    public function duplicate(
        string $id,
        InvoiceRepository $repository,
        InvoiceFactory $factory,
        EntityManagerInterface $entityManager,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        $copy = $factory->duplicate($this->reachable($repository->find($id), LedgerPermission::EDIT));
        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($copy));
    }

    /**
     * Numbered, frozen, booked: the draft becomes the document.
     */
    #[Route(path: 'emit/{id}', name: self::ROUTE_EMIT, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(InvoiceDto::class)]
    public function emit(
        string $id,
        InvoiceRepository $repository,
        InvoiceEmissionService $emission,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        $invoice = $this->reachable($repository->find($id), LedgerPermission::EDIT);

        return self::apiResponseSuccess(data: $normalizer->normalize($this->withinTheRules(fn () => $emission->emit($invoice))));
    }

    /**
     * A credit note cancelling the bill, emitted at once: the bill itself never changes.
     */
    #[Route(path: 'credit-note/{id}', name: self::ROUTE_CREDIT_NOTE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: CreateCreditNoteDto::class, attributeName: 'createCreditNoteDto')]
    #[ApiResponseData(InvoiceDto::class)]
    public function creditNote(
        string $id,
        CreateCreditNoteDto $createCreditNoteDto,
        InvoiceRepository $repository,
        InvoiceFactory $factory,
        InvoiceEmissionService $emission,
        EntityManagerInterface $entityManager,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        $bill = $this->reachable($repository->find($id), LedgerPermission::EDIT);

        $credit = $this->withinTheRules(function () use ($bill, $createCreditNoteDto, $factory, $emission, $entityManager): Invoice {
            $credit = $factory->createCreditNote($bill, $createCreditNoteDto->amount, $createCreditNoteDto->reason);
            $entityManager->flush();

            return $emission->emit($credit);
        });

        return self::apiResponseSuccess(data: $normalizer->normalize($credit));
    }

    /**
     * Only a draft is deleted; an emitted document is cancelled by a credit note.
     */
    #[Route(path: 'delete/{id}', name: self::ROUTE_DELETE, methods: [Request::METHOD_DELETE], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    public function delete(
        string $id,
        InvoiceRepository $repository,
        EntityManagerInterface $entityManager,
    ): ApiResponse {
        $invoice = $this->reachable($repository->find($id), LedgerPermission::EDIT);

        if (! $invoice->isEditable()) {
            throw new BadRequestHttpException('An emitted document is cancelled by a credit note, not deleted.');
        }

        $entityManager->remove($invoice);
        $entityManager->flush();

        return self::apiResponseSuccess();
    }

    private function partyOf(
        Ledger $ledger,
        string $id,
        PartyRepository $repository
    ): Party {
        $party = $repository->find($id);

        if (! $party instanceof Party || $party->getLedger() !== $ledger) {
            throw new BadRequestHttpException('The party is not one of these books.');
        }

        return $party;
    }
}
