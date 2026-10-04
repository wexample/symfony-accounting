<?php

namespace Wexample\SymfonyAccounting\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Entity\Traits\BelongsToLedgerTrait;
use Wexample\SymfonyAccounting\Enum\FiscalYearStatus;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * An accounting period with its own start and end: calendar years, offset years
 * (July → June), long or short first years all fit.
 */
#[ORM\Entity(repositoryClass: FiscalYearRepository::class)]
#[ORM\Table(name: 'accounting_fiscal_year')]
class FiscalYear extends AbstractEntity
{
    use BelongsToLedgerTrait;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    protected DateTimeImmutable $dateStart;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    protected DateTimeImmutable $dateEnd;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: FiscalYearStatus::class)]
    protected FiscalYearStatus $status = FiscalYearStatus::Open;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $dateClosed = null;

    /** Net result computed at closing: profit if positive, loss if negative. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    protected ?string $result = null;

    public function __toString(): string
    {
        return $this->getLabel();
    }

    public function getLabel(): string
    {
        return $this->dateStart->format('Y') === $this->dateEnd->format('Y')
            ? $this->dateEnd->format('Y')
            : $this->dateStart->format('Y').'-'.$this->dateEnd->format('Y');
    }

    public function getDateStart(): DateTimeImmutable
    {
        return $this->dateStart;
    }

    public function setDateStart(DateTimeImmutable $dateStart): static
    {
        $this->dateStart = $dateStart->setTime(0, 0);

        return $this;
    }

    public function getDateEnd(): DateTimeImmutable
    {
        return $this->dateEnd;
    }

    public function setDateEnd(DateTimeImmutable $dateEnd): static
    {
        $this->dateEnd = $dateEnd->setTime(0, 0);

        return $this;
    }

    public function contains(DateTimeInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return $day >= $this->dateStart->format('Y-m-d') && $day <= $this->dateEnd->format('Y-m-d');
    }

    public function getStatus(): FiscalYearStatus
    {
        return $this->status;
    }

    public function setStatus(FiscalYearStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isClosed(): bool
    {
        return FiscalYearStatus::Closed === $this->status;
    }

    public function getDateClosed(): ?DateTimeImmutable
    {
        return $this->dateClosed;
    }

    public function setDateClosed(?DateTimeImmutable $dateClosed): static
    {
        $this->dateClosed = $dateClosed;

        return $this;
    }

    public function getResult(): ?int
    {
        return null === $this->result ? null : (int) $this->result;
    }

    public function setResult(?int $result): static
    {
        $this->result = null === $result ? null : (string) $result;

        return $this;
    }
}
