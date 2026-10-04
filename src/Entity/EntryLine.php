<?php

namespace Wexample\SymfonyAccounting\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Enum\VatRole;
use Wexample\SymfonyAccounting\Repository\EntryLineRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * One line of an entry: an amount on the debit or the credit side of an account,
 * never both. With a party, the line belongs to that party's auxiliary account.
 *
 * `vatCode` and `vatRole` tag the lines VAT returns are computed from.
 */
#[ORM\Entity(repositoryClass: EntryLineRepository::class)]
#[ORM\Table(name: 'accounting_entry_line')]
#[ORM\Index(columns: ['letter'])]
#[ORM\Index(columns: ['vat_code'])]
class EntryLine extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: JournalEntry::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected JournalEntry $entry;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    protected Account $account;

    #[ORM\ManyToOne(targetEntity: Party::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Party $party = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $label;

    #[ORM\Column(type: Types::BIGINT)]
    protected string $debit = '0';

    #[ORM\Column(type: Types::BIGINT)]
    protected string $credit = '0';

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    protected ?string $letter = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $dateLettered = null;

    #[ORM\Column(type: Types::STRING, length: 30, nullable: true)]
    protected ?string $vatCode = null;

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true, enumType: VatRole::class)]
    protected ?VatRole $vatRole = null;

    /** Due date of a receivable or payable, for aged balances. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $dateDue = null;

    /** Amount in the document's currency when it differs from the ledger's. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    protected ?string $currencyAmount = null;

    #[ORM\Column(type: Types::STRING, length: 3, nullable: true)]
    protected ?string $currencyCode = null;

    #[ORM\Column(type: Types::INTEGER)]
    protected int $position = 0;

    public static function debit(
        Account $account,
        int $amount,
        string $label,
        ?Party $party = null
    ): self {
        return (new self())->setAccount($account)->setAmount($amount)->setLabel($label)->setParty($party);
    }

    public static function credit(
        Account $account,
        int $amount,
        string $label,
        ?Party $party = null
    ): self {
        return (new self())->setAccount($account)->setAmount(-$amount)->setLabel($label)->setParty($party);
    }

    public function getEntry(): JournalEntry
    {
        return $this->entry;
    }

    public function setEntry(JournalEntry $entry): static
    {
        $this->entry = $entry;

        return $this;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function setAccount(Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getParty(): ?Party
    {
        return $this->party;
    }

    public function setParty(?Party $party): static
    {
        $this->party = $party;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = mb_substr($label, 0, 255);

        return $this;
    }

    public function getDebit(): int
    {
        return (int) $this->debit;
    }

    public function getCredit(): int
    {
        return (int) $this->credit;
    }

    /**
     * Debit minus credit.
     */
    public function getBalance(): int
    {
        return $this->getDebit() - $this->getCredit();
    }

    /**
     * Positive: debit, negative: credit. Zero is refused: a line moves money.
     */
    public function setAmount(int $amount): static
    {
        $this->debit = (string) max(0, $amount);
        $this->credit = (string) max(0, -$amount);

        return $this;
    }

    public function getLetter(): ?string
    {
        return $this->letter;
    }

    public function setLetter(
        ?string $letter,
        ?DateTimeImmutable $date = null
    ): static {
        $this->letter = $letter;
        $this->dateLettered = null === $letter ? null : ($date ?? new DateTimeImmutable())->setTime(0, 0);

        return $this;
    }

    public function getDateLettered(): ?DateTimeImmutable
    {
        return $this->dateLettered;
    }

    public function getVatCode(): ?string
    {
        return $this->vatCode;
    }

    public function getVatRole(): ?VatRole
    {
        return $this->vatRole;
    }

    public function setVat(
        ?string $vatCode,
        ?VatRole $vatRole
    ): static {
        $this->vatCode = $vatCode;
        $this->vatRole = null === $vatCode ? null : $vatRole;

        return $this;
    }

    public function getDateDue(): ?DateTimeImmutable
    {
        return $this->dateDue;
    }

    public function setDateDue(?DateTimeImmutable $dateDue): static
    {
        $this->dateDue = $dateDue?->setTime(0, 0);

        return $this;
    }

    public function getCurrencyAmount(): ?int
    {
        return null === $this->currencyAmount ? null : (int) $this->currencyAmount;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrency(
        ?int $amount,
        ?string $currencyCode
    ): static {
        $this->currencyAmount = null === $amount ? null : (string) $amount;
        $this->currencyCode = $currencyCode;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /**
     * The auxiliary account code the line belongs to, if any: the party's
     * customer code on a customer account, its supplier code otherwise.
     */
    public function getAuxiliaryCode(?string $customersPrefix = null): ?string
    {
        if (! $this->party) {
            return null;
        }

        $customerSide = null !== $customersPrefix
            ? $this->account->startsWith($customersPrefix)
            : $this->party->isCustomer() && ! $this->party->isSupplier();

        return $customerSide
            ? ($this->party->getCustomerCode() ?? $this->party->getSupplierCode())
            : ($this->party->getSupplierCode() ?? $this->party->getCustomerCode());
    }
}
