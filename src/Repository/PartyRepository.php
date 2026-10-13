<?php

namespace Wexample\SymfonyAccounting\Repository;

use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyHelpers\Repository\AbstractRepository;

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
        if ($vatNumber) {
            $party = $this->findOneBy(['ledger' => $ledger, 'customer' => true, 'vatNumber' => $vatNumber]);

            if ($party) {
                return $party;
            }
        }

        return $email ? $this->findOneBy(['ledger' => $ledger, 'customer' => true, 'email' => $email]) : null;
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
