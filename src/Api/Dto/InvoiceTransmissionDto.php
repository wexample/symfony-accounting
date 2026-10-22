<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\InvoiceTransmission;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A document sent over an electronic network — Peppol —, and where it stands
 * as its access point reports it.
 */
class InvoiceTransmissionDto extends AbstractEntityDto
{
    public string $provider;

    public string $reference;

    public string $receiver;

    public string $status;

    public ?string $message;

    public string $dateSent;

    public string $dateUpdated;

    /**
     * @param InvoiceTransmission $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);

        $dto->provider = $entity->getProvider();
        $dto->reference = $entity->getReference();
        $dto->receiver = $entity->getReceiver();
        $dto->status = $entity->getStatus()->value;
        $dto->message = $entity->getMessage();
        $dto->dateSent = $entity->getDateSent()->format(DATE_ATOM);
        $dto->dateUpdated = $entity->getDateUpdated()->format(DATE_ATOM);

        return $dto;
    }
}
