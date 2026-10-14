<?php

namespace Wexample\SymfonyAccounting\Repository;

use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;
use Wexample\SymfonyOrganization\Entity\Organization;

/**
 * @method Party|null find($id, $lockMode = null, $lockVersion = null)
 * @method Party|null findOneBy(array $criteria, array $orderBy = null)
 * @method Party[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PartyRepository extends AbstractRepository
{
    public static function getEntityClassName(): string
    {
        return Party::class;
    }

    /**
     * @return list<Party> The parties of the ledger, by the name of their organization.
     */
    public function findByLedgerSortedByName(Ledger $ledger): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.organization', 'o')
            ->addSelect('o')
            ->andWhere('p.ledger = :ledger')
            ->setParameter('ledger', $ledger->getId(), UuidType::NAME)
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByOrganization(
        Ledger $ledger,
        Organization $organization
    ): ?Party {
        return $this->findOneBy(['ledger' => $ledger, 'organization' => $organization]);
    }

    public function findOneByCustomerCode(
        Ledger $ledger,
        string $code
    ): ?Party {
        return $this->findOneBy(['ledger' => $ledger, 'customerCode' => $code]);
    }

    public function findOneBySupplierCode(
        Ledger $ledger,
        string $code
    ): ?Party {
        return $this->findOneBy(['ledger' => $ledger, 'supplierCode' => $code]);
    }

    public function findOneByExternalReference(
        Ledger $ledger,
        string $externalReference
    ): ?Party {
        return $this->findOneBy(['ledger' => $ledger, 'externalReference' => $externalReference]);
    }

    /**
     * A customer known by its VAT number, else by its email: who a buyer already is in the books.
     */
    public function findOneCustomer(
        Ledger $ledger,
        ?string $vatNumber,
        ?string $email
    ): ?Party {
        if ($vatNumber && $party = $this->findOneByIdentity($ledger, 'vatNumber', $vatNumber, customer: true)) {
            return $party;
        }

        return $email ? $this->findOneByIdentity($ledger, 'email', $email, customer: true) : null;
    }

    /**
     * @param string $vatNumber Normalized, as Organization::setVatNumber() stores it.
     */
    public function findOneByVatNumber(
        Ledger $ledger,
        string $vatNumber
    ): ?Party {
        return $this->findOneByIdentity($ledger, 'vatNumber', $vatNumber);
    }

    /**
     * A party of the ledger by a field of its organization's identity.
     */
    private function findOneByIdentity(
        Ledger $ledger,
        string $field,
        string $value,
        ?bool $customer = null
    ): ?Party {
        $query = $this->createQueryBuilder('p')
            ->join('p.organization', 'o')
            ->andWhere('p.ledger = :ledger')
            ->andWhere('o.'.$field.' = :value')
            ->setParameter('ledger', $ledger->getId(), UuidType::NAME)
            ->setParameter('value', $value)
            ->setMaxResults(1);

        if (null !== $customer) {
            $query->andWhere('p.customer = :customer')->setParameter('customer', $customer, Types::BOOLEAN);
        }

        return $query->getQuery()->getOneOrNullResult();
    }

    public function findOneByIban(
        Ledger $ledger,
        string $iban
    ): ?Party {
        return $this->findOneBy(['ledger' => $ledger, 'iban' => strtoupper(str_replace(' ', '', $iban))]);
    }

    /**
     * Codes already used, to keep generated ones unique.
     *
     * @return list<string>
     */
    public function findUsedCodes(Ledger $ledger): array
    {
        $codes = [];

        foreach ($this->findBy(['ledger' => $ledger]) as $party) {
            array_push($codes, ...array_filter([$party->getCustomerCode(), $party->getSupplierCode()]));
        }

        return $codes;
    }
}
