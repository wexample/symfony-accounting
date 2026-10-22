<?php

namespace Wexample\SymfonyAccounting\Api\Normalizer\Entity\JournalEntry;

use ArrayObject;
use Wexample\SymfonyAccounting\Api\Dto\JournalEntryDto;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Entity\Traits\Manipulator\JournalEntryEntityManipulatorTrait;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;
use Wexample\SymfonyHelpers\Interface\NormalizableDataInterface;
use Wexample\SymfonyHelpers\Normalizer\AbstractEntityNormalizer;

class DefaultJournalEntryNormalizer extends AbstractEntityNormalizer
{
    use JournalEntryEntityManipulatorTrait;

    public function normalizeEntity(
        JournalEntry|AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|NormalizableDataInterface|null {
        return JournalEntryDto::fromEntity($entity);
    }

    /**
     * The accounts and parties its lines name, beside the lines: an entry is
     * read with them, and a relationship of a relationship is otherwise given
     * by id only.
     *
     * @param JournalEntry $entity
     */
    protected function normalizeRelationships(
        AbstractEntity $entity,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|null {
        if (! $this->shouldAutoRelationships($context) || ! isset($this->normalizer)) {
            return [];
        }

        $nestedContext = ['auto_relationships' => false] + $context;
        $relationships = [];

        foreach ($entity->getLines() as $line) {
            foreach ([$line->getAccount(), $line->getParty()] as $related) {
                if ($related && ! isset($relationships[(string) $related->getId()])) {
                    $relationships[(string) $related->getId()] = $this->normalizer->normalize($related, $format, $nestedContext);
                }
            }
        }

        return $relationships;
    }
}
