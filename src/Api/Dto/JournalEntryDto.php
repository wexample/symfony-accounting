<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\EntryLine;
use Wexample\SymfonyAccounting\Entity\Journal;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * An entry of a journal, under the names of its entity: its journal and its
 * lines travel as relationships.
 */
class JournalEntryDto extends AbstractEntityDto
{
    public Journal $journal;

    public ?int $number;

    public string $date;

    public string $label;

    public ?string $pieceReference;

    public string $status;

    /** @var list<EntryLine> */
    public array $lines;

    /**
     * @param JournalEntry $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);

        $dto->journal = $entity->getJournal();
        $dto->number = $entity->getNumber();
        $dto->date = $entity->getDate()->format('Y-m-d');
        $dto->label = $entity->getLabel();
        $dto->pieceReference = $entity->getPieceReference();
        $dto->status = $entity->getStatus()->value;
$dto->lines = $entity->getLines()->toArray();

        return $dto;
    }
}
