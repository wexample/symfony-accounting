<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\Account;
use Wexample\SymfonyAccounting\Entity\EntryLine;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A line of an entry, debit and credit in minor units; its account and its
 * party travel as relationships. Lettering addresses a line by its id.
 */
class EntryLineDto extends AbstractEntityDto
{
    public Account $account;

    public ?Party $party;

    public string $label;

    public int $debit;

    public int $credit;

    public ?string $letter;

    public ?string $dateDue;

    public int $position;

    /**
     * @param EntryLine $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);

        $dto->account = $entity->getAccount();
        $dto->party = $entity->getParty();
        $dto->label = $entity->getLabel();
        $dto->debit = $entity->getDebit();
        $dto->credit = $entity->getCredit();
        $dto->letter = $entity->getLetter();
        $dto->dateDue = $entity->getDateDue()?->format('Y-m-d');
        $dto->position = $entity->getPosition();

        return $dto;
    }
}
