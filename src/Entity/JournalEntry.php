<?php

namespace Wexample\SymfonyAccounting\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Entity\Traits\BelongsToLedgerTrait;
use Wexample\SymfonyAccounting\Enum\EntryStatus;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * One balanced accounting entry (FEC "écriture"): lines whose debits equal credits.
 *
 * It is numbered when posted, without gaps within its fiscal year. `sourceType`
 * and `sourceId` say what produced it (an invoice, an allocation, a closing), so
 * that a document is never booked twice.
 */
#[ORM\Entity(repositoryClass: JournalEntryRepository::class)]
#[ORM\Table(name: 'accounting_journal_entry')]
#[ORM\Index(columns: ['source_type', 'source_id'])]
#[ORM\UniqueConstraint(columns: ['fiscal_year_id', 'number'])]
class JournalEntry extends AbstractEntity
{
    use BelongsToLedgerTrait;

    #[ORM\ManyToOne(targetEntity: Journal::class)]
    #[ORM\JoinColumn(nullable: false)]
    protected Journal $journal;

    #[ORM\ManyToOne(targetEntity: FiscalYear::class)]
    #[ORM\JoinColumn(nullable: false)]
    protected FiscalYear $fiscalYear;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $number = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    protected DateTimeImmutable $date;

    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $label;

    /** The supporting document's reference (invoice number, bank reference). */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    protected ?string $pieceReference = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $pieceDate = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    protected ?string $sourceType = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    protected ?string $sourceId = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: EntryStatus::class)]
    protected EntryStatus $status = EntryStatus::Draft;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $dateValidated = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $dateCreated;

    /** @var Collection<int, EntryLine> */
    #[ORM\OneToMany(targetEntity: EntryLine::class, mappedBy: 'entry', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    protected Collection $lines;

    public function __construct()
    {
        parent::__construct();
        $this->lines = new ArrayCollection();
        $this->dateCreated = new DateTimeImmutable();
    }

    public function getJournal(): Journal
    {
        return $this->journal;
    }

    public function setJournal(Journal $journal): static
    {
        $this->journal = $journal;

        return $this;
    }

    public function getFiscalYear(): FiscalYear
    {
        return $this->fiscalYear;
    }

    public function setFiscalYear(FiscalYear $fiscalYear): static
    {
        $this->fiscalYear = $fiscalYear;

        return $this;
    }

    public function getNumber(): ?int
    {
        return $this->number;
    }

    public function setNumber(?int $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getDate(): DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(DateTimeImmutable $date): static
    {
        $this->date = $date->setTime(0, 0);

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

    public function getPieceReference(): ?string
    {
        return $this->pieceReference;
    }

    public function setPieceReference(?string $pieceReference): static
    {
        $this->pieceReference = $pieceReference;

        return $this;
    }

    public function getPieceDate(): ?DateTimeImmutable
    {
        return $this->pieceDate;
    }

    public function setPieceDate(?DateTimeImmutable $pieceDate): static
    {
        $this->pieceDate = $pieceDate?->setTime(0, 0);

        return $this;
    }

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function getSourceId(): ?string
    {
        return $this->sourceId;
    }

    public function setSource(
        ?string $sourceType,
        ?string $sourceId
    ): static {
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;

        return $this;
    }

    public function getStatus(): EntryStatus
    {
        return $this->status;
    }

    public function setStatus(EntryStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getDateValidated(): ?DateTimeImmutable
    {
        return $this->dateValidated;
    }

    public function setDateValidated(?DateTimeImmutable $dateValidated): static
    {
        $this->dateValidated = $dateValidated;

        return $this;
    }

    public function getDateCreated(): DateTimeImmutable
    {
        return $this->dateCreated;
    }

    /**
     * @return Collection<int, EntryLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(EntryLine $line): static
    {
        if (! $this->lines->contains($line)) {
            $line->setPosition($this->lines->count());
            $this->lines->add($line);
            $line->setEntry($this);
        }

        return $this;
    }

    public function removeLine(EntryLine $line): static
    {
        $this->lines->removeElement($line);

        return $this;
    }

    public function getTotalDebit(): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            $total += $line->getDebit();
        }

        return $total;
    }

    public function getTotalCredit(): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            $total += $line->getCredit();
        }

        return $total;
    }

    public function isBalanced(): bool
    {
        return $this->getTotalDebit() === $this->getTotalCredit();
    }
}
