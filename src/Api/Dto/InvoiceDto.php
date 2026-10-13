<?php

namespace Wexample\SymfonyAccounting\Api\Dto;

use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Entity\InvoiceRelation;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyApi\Api\Dto\AbstractEntityDto;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A document of the books — bill, credit note, quotation… — with what it asks
 * and what answered it. Amounts are in the currency's minor unit; the party
 * and the items travel as relationships.
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

    public int $amountNet;

    public int $amountVat;

    /** The document's own total, as issued. */
    public int $amountTotal;

    /** What emitted credit notes took back. */
    public int $amountCredited;

    public int $amountPaid;

    public int $amountDue;

    public ?string $paymentReference;

    /** The term set on the document; null when it follows the party's or the ledger's. */
    public ?int $paymentTermDays;

    /** The term it follows when it sets none. */
    public int $paymentTermDaysDefault;

    /** @var list<InvoiceItem> */
    public array $items;

    /**
     * The documents linked to this one, both ways: its quotation, bill, credit
     * notes, deposits, penalties… `relation` is an InvoiceRelationType, `way`
     * says whether this document is its source (`to`) or its target (`from`).
     *
     * @var list<array{id: string, number: ?string, type: string, status: string, relation: string, way: string, amount: ?int}>
     */
    public array $related;

    /**
     * @param Invoice $entity
     */
    public static function fromEntity(AbstractEntity $entity): self
    {
        $dto = parent::fromEntity($entity);
        $breakdown = $entity->calcPriceBreakdown();

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
        $dto->amountNet = $breakdown->getNet();
        $dto->amountVat = $breakdown->getVat();
        $dto->amountTotal = $breakdown->getFinal();
        $dto->amountCredited = $entity->calcCreditedAmount();
        $dto->amountPaid = $entity->calcPaidAmount();
        $dto->amountDue = $entity->calcRemainingAmount();
        $dto->paymentReference = $entity->getPaymentReference();
        $dto->paymentTermDays = $entity->getOwnPaymentTermDays();
        $dto->paymentTermDaysDefault = $entity->getDefaultPaymentTermDays();
        $dto->items = $entity->getItems()->toArray();
        $dto->related = [
            ...$entity->getOutgoingRelations()->map(fn (InvoiceRelation $relation) => self::related($relation, $relation->getTarget(), 'to'))->getValues(),
            ...$entity->getIncomingRelations()->map(fn (InvoiceRelation $relation) => self::related($relation, $relation->getSource(), 'from'))->getValues(),
        ];

        return $dto;
    }

    private static function related(
        InvoiceRelation $relation,
        Invoice $other,
        string $way
    ): array {
        return [
            'id' => (string) $other->getId(),
            'number' => $other->getNumber(),
            'type' => $other->getType()->value,
            'status' => $other->getStatus()->value,
            'relation' => $relation->getType()->value,
            'way' => $way,
            'amount' => $relation->getAmount(),
        ];
    }
}
