<?php

namespace Wexample\SymfonyAccounting\Service\Ledger;

use Doctrine\ORM\EntityManagerInterface;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Repository\PartyRepository;

/**
 * Third parties and their auxiliary account codes.
 */
class PartyService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PartyRepository $partyRepository,
    ) {
    }

    public function create(
        Ledger $ledger,
        string $name,
        bool $customer = true,
        bool $supplier = false,
        ?string $countryCode = null,
    ): Party {
        $party = (new Party())
            ->setLedger($ledger)
            ->setName($name)
            ->setCustomer($customer)
            ->setSupplier($supplier)
            ->setCountryCode($countryCode ?? $ledger->getCountryCode());

        $this->assignCodes($party);
        $this->entityManager->persist($party);
        $this->entityManager->flush();

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
