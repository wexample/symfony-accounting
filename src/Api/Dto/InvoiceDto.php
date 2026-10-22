<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A document of the books — bill, credit note, quotation… —, under the names
 * of its entity; the party and the items travel as relationships. What is
 * computed from it — its amounts, the term it follows, the documents linked to
 * it — travels in its metadata (DefaultInvoiceNormalizer).
 */
class InvoiceDto extends AbstractEntityDto
{
    public string $type;

    public string $direction;

    public string $status;

    public ?string $number;

    public ?string $title;

    public ?string $note;

    public ?Party $party;

    public string $currencyCode;

    /** For a document in another currency than the ledger's: one unit of it in the ledger's currency, fixed at emission. */
    public ?string $exchangeRate;

    public string $dateInvoice;

    public ?string $dateDue;

    public ?string $dateValidUntil;

    public ?string $datePaid;

    public ?string $paymentReference;

    /** The term set on the document; null when it follows the party's or the ledger's. */
    public ?int $paymentTermDays;

    /** @var list<InvoiceItem> */
    public array $items;

    /**
     * @param Invoice $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);

        $dto->type = $entity->getType()->value;
        $dto->direction = $entity->getDirection()->value;
        $dto->status = $entity->getStatus()->value;
        $dto->number = $entity->getNumber();
        $dto->title = $entity->getTitle();
        $dto->note = $entity->getNote();
        $dto->party = $entity->getParty();
        $dto->currencyCode = $entity->getCurrencyCode();
        $dto->exchangeRate = $entity->getExchangeRate();
        $dto->dateInvoice = $entity->getDateInvoice()->format('Y-m-d');
        $dto->dateDue = $entity->getType()->isPayable() ? $entity->calcDateDue()->format('Y-m-d') : null;
        $dto->dateValidUntil = $entity->getDateValidUntil()?->format('Y-m-d');
        $dto->datePaid = $entity->getDatePaid()?->format('Y-m-d');
        $dto->paymentReference = $entity->getPaymentReference();
        $dto->paymentTermDays = $entity->getOwnPaymentTermDays();
        $dto->items = $entity->getItems()->toArray();

        return $dto;
    }
}
