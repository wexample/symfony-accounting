<?php

namespace Wexample\SymfonyAccounting\Api\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccounting\Api\Dto\LedgerDto;
use Wexample\SymfonyAccounting\Api\Dto\LedgerInputDto;
use Wexample\SymfonyAccounting\Api\Normalizer\Entity\Ledger\DefaultLedgerNormalizer;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\LedgerPermission;
use Wexample\SymfonyAccounting\Repository\BankAccountRepository;
use Wexample\SymfonyAccounting\Service\Ledger\LedgerAccessService;
use Wexample\SymfonyApi\Api\Attribute\ApiResponseData;
use Wexample\SymfonyApi\Api\Attribute\ValidateRequestContent;
use Wexample\SymfonyApi\Api\Class\ApiResponse;
use Wexample\SymfonyGeo\Entity\Country;
use Wexample\SymfonyHelpers\Controller\AbstractController;

/**
 * The books the reader may open.
 */
#[Route(path: 'api/ledger/', name: 'api_ledger_')]
class LedgerController extends AbstractAccountingApiController
{
    #[Route(path: 'list', name: self::ROUTE_LIST, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(LedgerDto::class, collection: true)]
    public function list(
        LedgerAccessService $access,
        DefaultLedgerNormalizer $normalizer,
    ): ApiResponse {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');

        return self::apiResponseCollection($normalizer->normalizeCollection($access->getAccessible()));
    }

    #[Route(path: 'show/{id}', name: self::ROUTE_SHOW, methods: AbstractController::ROUTE_OPTIONS_METHOD_ONLY_GET, options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ApiResponseData(LedgerDto::class)]
    public function show(
        string $id,
        DefaultLedgerNormalizer $normalizer,
    ): ApiResponse {
        return self::apiResponseSuccess(data: $normalizer->normalize($this->reachable($this->ledgerRepository->find($id))));
    }

    #[Route(path: 'update/{id}', name: self::ROUTE_UPDATE, methods: [Request::METHOD_POST], options: AbstractController::ROUTE_OPTIONS_ONLY_EXPOSE)]
    #[ValidateRequestContent(dto: LedgerInputDto::class, attributeName: 'ledgerInputDto')]
    #[ApiResponseData(LedgerDto::class)]
    public function update(
        string $id,
        Request $request,
        LedgerInputDto $ledgerInputDto,
        BankAccountRepository $bankAccountRepository,
        EntityManagerInterface $entityManager,
        DefaultLedgerNormalizer $normalizer,
    ): ApiResponse {
        $ledger = $this->reachable($this->ledgerRepository->find($id), LedgerPermission::ADMINISTER);
        $input = $ledgerInputDto;

        // The identity is the organization's, written through its books.
        $organization = $ledger->getOrganization();

        foreach (self::sentFields($request) as $field) {
            match ($field) {
                'name' => $input->name ? $organization->setName($input->name) : throw new BadRequestHttpException('Books have a name.'),
                'legalForm' => $organization->setLegalForm($input->legalForm),
                'legalIdentifier' => $organization->setLegalIdentifier($input->legalIdentifier),
                'vatNumber' => $organization->setVatNumber($input->vatNumber),
                'email' => $organization->setEmail($input->email),
                'phone' => $organization->setPhone($input->phone),
                'website' => $organization->setWebsite($input->website),
                'legalMentions' => $ledger->setLegalMentions($input->legalMentions),
                'postalAddress' => $organization->setPostalAddress($input->postalAddress),
                'postCode' => $organization->setPostCode($input->postCode),
                'city' => $organization->setCity($input->city),
                'country' => $organization->setCountry($input->country
                    ? ($entityManager->find(Country::class, $input->country) ?? throw new BadRequestHttpException('Unknown country.'))
                    : throw new BadRequestHttpException('Books are kept in a country.')),
                'invoicePrefix' => $ledger->setInvoicePrefix($input->invoicePrefix),
                'paymentTermDays' => $ledger->setPaymentTermDays((int) $input->paymentTermDays),
                'defaultBankAccount' => $ledger->setDefaultBankAccount($input->defaultBankAccount
                    ? $this->bankAccountOf($ledger, $input->defaultBankAccount, $bankAccountRepository)
                    : null),
                'settings' => $ledger->setSettings(array_filter(
                    [...$ledger->getSettings(), ...($input->settings ?? [])],
                    fn (mixed $value) => null !== $value
                )),
                default => null,
            };
        }

        $entityManager->flush();

        return self::apiResponseSuccess(data: $normalizer->normalize($ledger));
    }

    private function bankAccountOf(
        Ledger $ledger,
        string $id,
        BankAccountRepository $repository
    ): BankAccount {
        $bankAccount = $repository->find($id);

        if (! $bankAccount || $bankAccount->getLedger() !== $ledger) {
            throw new BadRequestHttpException('The bank account is not one of these books.');
        }

        return $bankAccount;
    }
}
