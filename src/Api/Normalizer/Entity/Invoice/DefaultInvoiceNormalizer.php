<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\Invoice;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\InvoiceDto;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceRelation;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\InvoiceEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultInvoiceNormalizer extends AbstractEntityNormalizer
{
    use InvoiceEntityManipulatorTrait;

    public function normalizeEntity(
        Invoice|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return InvoiceDto::fromEntity($entity);
    }

    /**
     * What is computed from the document, not a field of it. Amounts are in
     * the currency's minor unit: `amountTotal` is the document's own total as
     * issued, `amountCredited` what emitted credit notes took back.
     * `paymentTermDaysDefault` is the term it follows when it sets none.
     *
     * `related` are the documents linked to this one, both ways: its quotation,
     * bill, credit notes, deposits, penalties… `relation` is an
     * InvoiceRelationType, `way` says whether this document is its source
     * (`to`) or its target (`from`), `manual` whether it may be unlinked by
     * hand (`relationId`).
     *
     * @param Invoice $entity
     */
    protected function normalizeMetadata(
        AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|null {
        $breakdown = $entity->calcPriceBreakdown();

        return [
            'amountNet' => $breakdown->getNet(),
            'amountVat' => $breakdown->getVat(),
            'amountTotal' => $breakdown->getFinal(),
            'amountCredited' => $entity->calcCreditedAmount(),
            'amountPaid' => $entity->calcPaidAmount(),
            'amountDue' => $entity->calcRemainingAmount(),
            'paymentTermDaysDefault' => $entity->getDefaultPaymentTermDays(),
            'related' => [
                ...$entity->getOutgoingRelations()->map(fn (InvoiceRelation $relation) => self::related($relation, $relation->getTarget(), 'to'))->getValues(),
                ...$entity->getIncomingRelations()->map(fn (InvoiceRelation $relation) => self::related($relation, $relation->getSource(), 'from'))->getValues(),
            ],
        ];
    }

    /**
     * @return array{relationId: string, manual: bool, id: string, number: ?string, type: string, status: string, relation: string, way: string, amount: ?int}
     */
    private static function related(
        InvoiceRelation $relation,
        Invoice $other,
        string $way
    ): array {
        return [
            'relationId' => (string) $relation->getId(),
            'manual' => $relation->getType()->isManual(),
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
