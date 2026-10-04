<?php

namespace Wexample\SymfonyAccounting\Class;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Enum\VatRole;

/**
 * An entry being described before it is booked: accounts by number or by role,
 * amounts signed (+ debit, − credit). PostingService turns it into a JournalEntry.
 *
 * Lines on the same account, party, VAT code and label are merged, so callers can
 * add amounts item by item.
 */
final class EntryDraft
{
    /**
     * @var list<array{account: string|AccountRole, amount: int, label: string, party: ?Party, vatCode: ?string, vatRole: ?VatRole, dateDue: ?DateTimeImmutable}>
     */
    private array $lines = [];

    public ?string $pieceReference = null;

    public ?DateTimeImmutable $pieceDate = null;

    public ?string $sourceType = null;

    public ?string $sourceId = null;

    public function __construct(
        public JournalType|string $journal,
        public DateTimeImmutable $date,
        public string $label,
    ) {
    }

    public function source(
        string $type,
        string $id
    ): self {
        $this->sourceType = $type;
        $this->sourceId = $id;

        return $this;
    }

    public function piece(
        ?string $reference,
        ?DateTimeImmutable $date = null
    ): self {
        $this->pieceReference = $reference;
        $this->pieceDate = $date;

        return $this;
    }

    public function debit(
        string|AccountRole $account,
        int $amount,
        ?string $label = null,
        ?Party $party = null,
        ?string $vatCode = null,
        ?VatRole $vatRole = null,
        ?DateTimeImmutable $dateDue = null,
    ): self {
        return $this->add($account, $amount, $label, $party, $vatCode, $vatRole, $dateDue);
    }

    public function credit(
        string|AccountRole $account,
        int $amount,
        ?string $label = null,
        ?Party $party = null,
        ?string $vatCode = null,
        ?VatRole $vatRole = null,
        ?DateTimeImmutable $dateDue = null,
    ): self {
        return $this->add($account, -$amount, $label, $party, $vatCode, $vatRole, $dateDue);
    }

    /**
     * Signed amount: positive is a debit, negative a credit. Zero amounts are ignored.
     */
    public function add(
        string|AccountRole $account,
        int $amount,
        ?string $label = null,
        ?Party $party = null,
        ?string $vatCode = null,
        ?VatRole $vatRole = null,
        ?DateTimeImmutable $dateDue = null,
    ): self {
        if (0 === $amount) {
            return $this;
        }

        $label ??= $this->label;

        foreach ($this->lines as $index => $line) {
            if ($line['account'] === $account
                && $line['party'] === $party
                && $line['vatCode'] === $vatCode
                && $line['vatRole'] === $vatRole
                && $line['label'] === $label
                && ($line['amount'] > 0) === ($amount > 0)) {
                $this->lines[$index]['amount'] += $amount;

                return $this;
            }
        }

        $this->lines[] = [
            'account' => $account,
            'amount' => $amount,
            'label' => $label,
            'party' => $party,
            'vatCode' => $vatCode,
            'vatRole' => $vatRole,
            'dateDue' => $dateDue,
        ];

        return $this;
    }

    public function getLines(): array
    {
        return $this->lines;
    }

    public function getBalance(): int
    {
        return array_sum(array_column($this->lines, 'amount'));
    }

    public function isEmpty(): bool
    {
        return [] === $this->lines;
    }
}
