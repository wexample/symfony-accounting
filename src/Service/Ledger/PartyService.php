<?php

namespace Wexample\SymfonyAccounting\Service\Ledger;

use Doctrine\ORM\EntityManagerInterface;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
use Wexample\SymfonyGeo\Entity\Country;
use Wexample\SymfonyOrganization\Entity\Organization;
use Wexample\SymfonyOrganization\Service\OrganizationService;

/**
 * Third parties — organizations of the directory, as a ledger trades with
 * them — and their auxiliary account codes.
 */
class PartyService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PartyRepository $partyRepository,
        private readonly OrganizationService $organizationService,
    ) {
    }

    /**
     * @param Organization|string $organization An organization of the directory, or the name
     *                                          of a new one (in $country, else the ledger's).
     */
    public function create(
        Ledger $ledger,
        Organization|string $organization,
        bool $customer = true,
        bool $supplier = false,
        ?Country $country = null,
    ): Party {
        if (is_string($organization)) {
            $organization = $this->organizationService->create($organization, $country ?? $ledger->getCountry());
        }

        $party = (new Party())
            ->setLedger($ledger)
            ->setOrganization($organization)
            ->setCustomer($customer)
            ->setSupplier($supplier);

        $this->assignCodes($party);
        $this->entityManager->persist($party);
        $this->entityManager->flush();

        return $party;
    }

    /**
     * The party this organization already is to the ledger, else a new one;
     * an existing party gains the side it lacked.
     */
    public function findOrCreate(
        Ledger $ledger,
        Organization $organization,
        bool $customer = true,
        bool $supplier = false,
    ): Party {
        $party = $this->partyRepository->findOneByOrganization($ledger, $organization);

        if (! $party) {
            return $this->create($ledger, $organization, $customer, $supplier);
        }

        if (($customer && ! $party->isCustomer()) || ($supplier && ! $party->isSupplier())) {
            $party->setCustomer($party->isCustomer() || $customer)->setSupplier($party->isSupplier() || $supplier);
            $this->assignCodes($party);
            $this->entityManager->flush();
        }

        return $party;
    }

    /**
     * Gives a customer code ("CDUPONT") and/or a supplier code ("FDUPONT") to a
     * party lacking one, unique within its ledger.
     */
    public function assignCodes(Party $party): void
    {
        $used = $this->partyRepository->findUsedCodes($party->getLedger());

        if ($party->isCustomer() && ! $party->getCustomerCode()) {
            $party->setCustomerCode($code = $this->buildCode('C', $party->getName(), $used));
            $used[] = $code;
        }

        if ($party->isSupplier() && ! $party->getSupplierCode()) {
            $party->setSupplierCode($this->buildCode('F', $party->getName(), $used));
        }
    }

    /**
     * @param list<string> $used
     */
    public function buildCode(
        string $prefix,
        string $name,
        array $used
    ): string {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        $base = $prefix.substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $ascii)) ?: 'PARTY', 0, 12);
        $code = $base;
        $suffix = 1;

        while (in_array($code, $used, true)) {
            $code = $base.++$suffix;
        }

        return $code;
    }
}
