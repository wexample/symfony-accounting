<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\EntryLine;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * An entry of a journal with its lines, debit and credit in minor units.
 */
class JournalEntryDto extends AbstractEntityDto
{
    public string $journal;

    public ?int $number;

    public string $date;

    public string $label;

    public ?string $pieceReference;

    public string $status;

    /** @var list<array{account: string, label: string, debit: int, credit: int, letter: ?string}> */
    public array $lines;

    /**
     * @param JournalEntry $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);

        $dto->journal = $entity->getJournal()->getCode();
        $dto->number = $entity->getNumber();
        $dto->date = $entity->getDate()->format('Y-m-d');
        $dto->label = $entity->getLabel();
        $dto->pieceReference = $entity->getPieceReference();
        $dto->status = $entity->getStatus()->value;
        $dto->lines = array_map(fn (EntryLine $line) => [
            'account' => $line->getAccount()->getNumber(),
            'label' => $line->getLabel(),
            'debit' => $line->getDebit(),
            'credit' => $line->getCredit(),
            'letter' => $line->getLetter(),
        ], $entity->getLines()->toArray());

        return $dto;
    }
}
