<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A customer or a supplier of the books.
 */
class PartyDto extends AbstractEntityDto
{
    public string $name;

    public ?string $legalIdentifier;

    public ?string $vatNumber;

    public ?string $email;

    public ?string $phone;

    public bool $customer;

    public bool $supplier;

    public bool $individual;

    public ?string $customerCode;

    public ?string $supplierCode;

    public ?string $iban;

    public ?int $paymentTermDays;

    public ?string $externalReference;

    /**
     * @param Party $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);

        $dto->name = (string) $entity->getName();
        $dto->legalIdentifier = $entity->getLegalIdentifier();
        $dto->vatNumber = $entity->getVatNumber();
        $dto->email = $entity->getEmail();
        $dto->phone = $entity->getPhone();
        $dto->customer = $entity->isCustomer();
        $dto->supplier = $entity->isSupplier();
        $dto->individual = $entity->isIndividual();
        $dto->customerCode = $entity->getCustomerCode();
        $dto->supplierCode = $entity->getSupplierCode();
        $dto->iban = $entity->getIban();
        $dto->paymentTermDays = $entity->getPaymentTermDays();
        $dto->externalReference = $entity->getExternalReference();

        return $dto;
    }
}
