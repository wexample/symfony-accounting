<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\PartyDto;
use Wexample\SymfonyAccounting\Api\Dto\PartyInputDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\Party\DefaultPartyNormalizer;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
use Wexample\SymfonyAccounting\Service\Ledger\PartyService;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\LengthQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\PageQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\SearchQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\SortQueryOption;
use Wexample\SymfonyApi\Api\Attribute\QueryOption\StringQueryOption;
use Wexample\SymfonyApi\Api\Attribute\ValidateRequestContent;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyGeo\Entity\Country;
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

    #[Route(path: 'create', name: self::ROUTE_CREATE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: PartyInputDto::class, attributeName: 'partyInputDto')]
    #[ApiResponseData(PartyDto::class)]
    public function create(
        Request $request,
        PartyInputDto $partyInputDto,
        PartyService $partyService,
        EntityManagerInterface $entityManager,
        DefaultPartyNormalizer $normalizer,
    ): ApiResponse {
        if (! $partyInputDto->name) {
            throw new BadRequestHttpException('A party has a name.');
        }

        $ledger = $this->reachable($this->ledgerRepository->find((string) $partyInputDto->ledger), LedgerPermission::EDIT);
        $party = $partyService->create($ledger, $partyInputDto->name, $partyInputDto->customer ?? true, $partyInputDto->supplier ?? false);
        $this->write($party, $partyInputDto, self::sentFields($request), $entityManager);
        $partyService->assignCodes($party);
        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($party));
    }

    #[Route(path: 'update/{id}', name: self::ROUTE_UPDATE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: PartyInputDto::class, attributeName: 'partyInputDto')]
    #[ApiResponseData(PartyDto::class)]
    public function update(
        string $id,
        Request $request,
        PartyInputDto $partyInputDto,
        PartyRepository $repository,
        EntityManagerInterface $entityManager,
        DefaultPartyNormalizer $normalizer,
    ): ApiResponse {
        $party = $this->reachable($repository->find($id), LedgerPermission::EDIT);
        $this->write($party, $partyInputDto, array_diff(self::sentFields($request), ['ledger']), $entityManager);
        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($party));
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

    /**
     * @param list<string> $fields What the request sent.
     */
    private function write(
        Party $party,
        PartyInputDto $input,
        array $fields,
        EntityManagerInterface $entityManager
    ): void {
        foreach ($fields as $field) {
            match ($field) {
                'name' => $input->name ? $party->setName($input->name) : throw new BadRequestHttpException('A party has a name.'),
                'customer' => $party->setCustomer((bool) $input->customer),
                'supplier' => $party->setSupplier((bool) $input->supplier),
                'individual' => $party->setIndividual((bool) $input->individual),
                'email' => $party->setEmail($input->email),
                'phone' => $party->setPhone($input->phone),
                'legalIdentifier' => $party->setLegalIdentifier($input->legalIdentifier),
                'vatNumber' => $party->setVatNumber($input->vatNumber),
                'iban' => $party->setIban($input->iban),
                'paymentTermDays' => $party->setPaymentTermDays($input->paymentTermDays),
                'postalAddress' => $party->setPostalAddress($input->postalAddress),
                'postCode' => $party->setPostCode($input->postCode),
                'city' => $party->setCity($input->city),
                'country' => $party->setCountry($input->country
                    ? ($entityManager->find(Country::class, $input->country) ?? throw new BadRequestHttpException('Unknown country.'))
                    : null),
                'notes' => $party->setNotes($input->notes),
                'externalReference' => $party->setExternalReference($input->externalReference),
                default => null,
            };
        }
    }
}
