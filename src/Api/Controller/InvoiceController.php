<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\CreateCreditNoteDto;
use Wexample\SymfonyAccounting\Api\Dto\CreateInvoiceDto;
use Wexample\SymfonyAccounting\Api\Dto\InvoiceDto;
use Wexample\SymfonyAccounting\Api\Dto\InvoiceInputDto;
use Wexample\SymfonyAccounting\Api\Dto\InvoiceTransmissionDto;
use Wexample\SymfonyAccounting\Api\Dto\RelateInvoiceDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\Invoice\DefaultInvoiceNormalizer;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\InvoiceTransmission\DefaultInvoiceTransmissionNormalizer;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceRelation;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\InvoiceRelationType;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Repository\InvoiceTransmissionRepository;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
use Wexample\SymfonyAccounting\Service\Document\CiiInvoiceBuilder;
use Wexample\SymfonyAccounting\Service\Document\ElectronicInvoiceReceiver;
use Wexample\SymfonyAccounting\Service\Document\InvoicePdfService;
use Wexample\SymfonyAccounting\Service\Document\PeppolInvoiceService;
use Wexample\SymfonyAccounting\Service\Document\UblInvoiceBuilder;
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
 * credit note, printed, exchanged as electronic invoices. Reading asks
 * `accounting.view`, the rest `accounting.edit`.
 */
#[Route(path: '_api/invoice/', name: 'api_invoice_')]
class InvoiceController extends AbstractAccountingApiController
{
    final public const string ROUTE_EMIT = 'emit';

    final public const string ROUTE_CREDIT_NOTE = 'credit_note';

    final public const string ROUTE_PDF = 'pdf';

    final public const string ROUTE_ELECTRONIC = 'electronic';

    final public const string ROUTE_RECEIVE = 'receive';

    final public const string ROUTE_PEPPOL = 'peppol';

    final public const string ROUTE_PEPPOL_SEND = 'peppol_send';

    final public const string QUERY_OPTION_FORMAT = 'format';

    final public const string FORMAT_UBL = 'ubl';

    final public const string FORMAT_CII = 'cii';

    final public const string ROUTE_BILL = 'bill';

    final public const string ROUTE_DUPLICATE = 'duplicate';

    final public const string ROUTE_RELATE = 'relate';

    final public const string ROUTE_UNRELATE = 'unrelate';

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

    /**
     * An emitted document as a structured electronic invoice: UBL (Peppol, the
     * default) or CII (the XML of Factur-X).
     */
    #[Route(path: 'electronic/{id}', name: self::ROUTE_ELECTRONIC, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_FORMAT, default: self::FORMAT_UBL)]
    public function electronic(
        string $id,
        Request $request,
        InvoiceRepository $repository,
        UblInvoiceBuilder $ublBuilder,
        CiiInvoiceBuilder $ciiBuilder,
    ): Response {
        $invoice = $this->reachable($repository->find($id));
        $format = (string) self::getQueryOptionValue($request, self::QUERY_OPTION_FORMAT, self::FORMAT_UBL);
        $builder = match ($format) {
            self::FORMAT_UBL => $ublBuilder,
            self::FORMAT_CII => $ciiBuilder,
            default => throw new BadRequestHttpException('Unknown format: ubl or cii.'),
        };

        return new Response($this->withinTheRules(fn () => $builder->build($invoice)), Response::HTTP_OK, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => sprintf('attachment; filename="%s.%s.xml"', $invoice->getNumber(), $format),
        ]);
    }

    /**
     * A received electronic invoice (`file`, multipart): a Factur-X PDF, a CII or a
     * UBL XML, made a purchase draft. What a person should check comes as `warnings`.
     */
    #[Route(path: 'receive', name: self::ROUTE_RECEIVE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[StringQueryOption(key: self::QUERY_OPTION_LEDGER, required: true)]
    public function receive(
        Request $request,
        ElectronicInvoiceReceiver $receiver,
        EntityManagerInterface $entityManager,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        $ledger = $this->resolveLedger($request, LedgerPermission::EDIT);
        $file = $request->files->get('file');

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw new BadRequestHttpException('Send the invoice as the multipart field "file".');
        }

        $result = $this->withinTheRules(fn () => $receiver->receive($ledger, (string) file_get_contents($file->getPathname())));
        $entityManager->flush();

        return self::apiResponseSuccess(data: [
            'invoice' => $normalizer->normalize($result->invoice),
            'partyCreated' => $result->partyCreated,
            'warnings' => $result->warnings,
        ]);
    }

    /**
     * Where the document stands on Peppol: every transmission so far, the
     * latest first, and whether it can be sent (`reachable`, beside them).
     */
    #[Route(path: 'peppol/{id}', name: self::ROUTE_PEPPOL, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(InvoiceTransmissionDto::class, collection: true)]
    public function peppol(
        string $id,
        InvoiceRepository $repository,
        PeppolInvoiceService $peppolService,
        InvoiceTransmissionRepository $transmissionRepository,
        DefaultInvoiceTransmissionNormalizer $transmissionNormalizer,
    ): ApiResponse {
        $invoice = $this->reachable($repository->find($id));

        return self::apiResponseCollection(
            $transmissionNormalizer->normalizeCollection($transmissionRepository->findForInvoice($invoice)),
            ['reachable' => $peppolService->isReachable($invoice)]
        );
    }

    /**
     * An emitted sale sent to its client over Peppol, as UBL.
     */
    #[Route(path: 'peppol/{id}', name: self::ROUTE_PEPPOL_SEND, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(InvoiceTransmissionDto::class)]
    public function peppolSend(
        string $id,
        InvoiceRepository $repository,
        PeppolInvoiceService $peppolService,
        EntityManagerInterface $entityManager,
        DefaultInvoiceTransmissionNormalizer $transmissionNormalizer,
    ): ApiResponse {
        $invoice = $this->reachable($repository->find($id), LedgerPermission::EDIT);
        $transmission = $this->withinTheRules(fn () => $peppolService->send($invoice));
        $entityManager->flush();

        return self::apiResponseSuccess(data: $transmissionNormalizer->normalize($transmission));
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
        )
            ->setNote($createInvoiceDto->note)
            ->setCurrencyCode($createInvoiceDto->currencyCode ?? $ledger->getCurrencyCode())
            ->setExchangeRate($createInvoiceDto->exchangeRate));

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
                'currencyCode' => $invoice->setCurrencyCode($invoiceInputDto->currencyCode ?? $invoice->getLedger()->getCurrencyCode()),
                'exchangeRate' => $invoice->setExchangeRate($invoiceInputDto->exchangeRate),
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
     * Links the document to another of its ledger by hand, once chosen: a
     * purchase to the sale re-billing it, financing documents together. Emitted
     * documents may be linked too: a link changes neither of them.
     */
    #[Route(path: 'relate/{id}', name: self::ROUTE_RELATE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: RelateInvoiceDto::class, attributeName: 'relateInvoiceDto')]
    #[ApiResponseData(InvoiceDto::class)]
    public function relate(
        string $id,
        RelateInvoiceDto $relateInvoiceDto,
        InvoiceRepository $repository,
        InvoiceFactory $factory,
        EntityManagerInterface $entityManager,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        $invoice = $this->reachable($repository->find($id), LedgerPermission::EDIT);
        $other = $this->reachable($repository->find($relateInvoiceDto->other), LedgerPermission::EDIT);
        $type = InvoiceRelationType::tryFrom($relateInvoiceDto->type) ?? throw new BadRequestHttpException('Unknown link type.');

        $this->withinTheRules(fn () => 'from' === $relateInvoiceDto->way
            ? $factory->relate($other, $invoice, $type)
            : $factory->relate($invoice, $other, $type));
        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($invoice));
    }

    /**
     * Removes a link set by hand (`relationId` of `related`), answering with the
     * document at hand.
     */
    #[Route(path: 'unrelate/{id}/{relation}', name: self::ROUTE_UNRELATE, methods: [Request::METHOD_DELETE], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(InvoiceDto::class)]
    public function unrelate(
        string $id,
        string $relation,
        InvoiceRepository $repository,
        InvoiceFactory $factory,
        EntityManagerInterface $entityManager,
        DefaultInvoiceNormalizer $normalizer,
    ): ApiResponse {
        $invoice = $this->reachable($repository->find($id), LedgerPermission::EDIT);
        $link = $entityManager->find(InvoiceRelation::class, $relation);

        if (! $link || ($link->getSource() !== $invoice && $link->getTarget() !== $invoice)) {
            throw new NotFoundHttpException();
        }

        $this->withinTheRules(fn () => $factory->unrelate($link));
        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($invoice));
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
