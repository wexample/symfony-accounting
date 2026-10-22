<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A fiscal year of the ledger; its label (« 2026 », « 2025-2026 ») travels in
 * its metadata.
 */
class FiscalYearDto extends AbstractEntityDto
{
    public string $dateStart;

    public string $dateEnd;

    public string $status;

    public ?string $dateClosed;

    /** The result booked at closing, in minor units. */
    public ?int $result;

    /**
     * @param FiscalYear $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);

        $dto->dateStart = $entity->getDateStart()->format('Y-m-d');
        $dto->dateEnd = $entity->getDateEnd()->format('Y-m-d');
        $dto->status = $entity->getStatus()->value;
        $dto->dateClosed = $entity->getDateClosed()?->format(DATE_ATOM);
        $dto->result = $entity->getResult();

        return $dto;
    }
}
