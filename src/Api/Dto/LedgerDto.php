<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A set of books, as whoever may read it sees it: who keeps it, how it invoices.
 */
class LedgerDto extends AbstractEntityDto
{
    /** The organization of the directory it is: the same for each ledger it appears in. */
    public string $organization;

    public string $name;

    public ?string $legalForm;

    public ?string $legalIdentifier;

    public ?string $vatNumber;

    public ?string $email;

    public string $currencyCode;

    public bool $vatSubject;

    public ?string $invoicePrefix;

    public int $paymentTermDays;

    /** The application's own reference of what the books belong to. */
    public ?string $externalReference;

    /**
     * @param Ledger $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);

        $dto->organization = (string) $entity->getOrganization()->getId();
        $dto->name = (string) $entity->getName();
        $dto->legalForm = $entity->getLegalForm();
        $dto->legalIdentifier = $entity->getLegalIdentifier();
        $dto->vatNumber = $entity->getVatNumber();
        $dto->email = $entity->getEmail();
        $dto->currencyCode = $entity->getCurrencyCode();
        $dto->vatSubject = $entity->isVatSubject();
        $dto->invoicePrefix = $entity->getInvoicePrefix();
        $dto->paymentTermDays = $entity->getPaymentTermDays();
        $dto->externalReference = $entity->getExternalReference();

        return $dto;
    }
}
