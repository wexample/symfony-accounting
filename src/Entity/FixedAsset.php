<?php

namespace Wexample\SymfonyAccounting\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Entity\Traits\BelongsToLedgerTrait;
use Wexample\SymfonyAccounting\Repository\FixedAssetRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * Something the company keeps and uses over several years (a computer, a car,
 * software), depreciated straight-line from the day it is put into service,
 * pro rata temporis.
 *
 * Accounts: the asset account (class 2, where the purchase was booked), its
 * depreciation account and the depreciation expense default to what the
 * jurisdiction derives; each can be set.
 */
#[ORM\Entity(repositoryClass: FixedAssetRepository::class)]
#[ORM\Table(name: 'accounting_fixed_asset')]
class FixedAsset extends AbstractEntity
{
    use BelongsToLedgerTrait;

    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $label;

    #[ORM\Column(type: Types::STRING, length: 20)]
    protected string $accountNumber;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    protected ?string $depreciationAccountNumber = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    protected ?string $expenseAccountNumber = null;

    /** Date put into service: depreciation starts that day. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    protected DateTimeImmutable $dateInService;

    /** Cost before VAT, minor units. */
    #[ORM\Column(type: Types::BIGINT)]
    protected string $cost;

    #[ORM\Column(type: Types::BIGINT)]
    protected string $residualValue = '0';

    #[ORM\Column(type: Types::INTEGER)]
    protected int $durationMonths;

    /** Depreciation booked before the asset entered this ledger (books taken over). */
    #[ORM\Column(type: Types::BIGINT)]
    protected string $priorDepreciation = '0';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $dateDisposed = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Invoice $invoice = null;

    public function __toString(): string
    {
        return $this->label;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getAccountNumber(): string
    {
        return $this->accountNumber;
    }

    public function setAccountNumber(string $accountNumber): static
    {
        $this->accountNumber = $accountNumber;

        return $this;
    }

    public function getDepreciationAccountNumber(): ?string
    {
        return $this->depreciationAccountNumber;
    }

    public function setDepreciationAccountNumber(?string $depreciationAccountNumber): static
    {
        $this->depreciationAccountNumber = $depreciationAccountNumber;

        return $this;
    }

    public function getExpenseAccountNumber(): ?string
    {
        return $this->expenseAccountNumber;
    }

    public function setExpenseAccountNumber(?string $expenseAccountNumber): static
    {
        $this->expenseAccountNumber = $expenseAccountNumber;

        return $this;
    }

    public function getDateInService(): DateTimeImmutable
    {
        return $this->dateInService;
    }

    public function setDateInService(DateTimeImmutable $dateInService): static
    {
        $this->dateInService = $dateInService->setTime(0, 0);

        return $this;
    }

    /**
     * The day after the last depreciated day.
     */
    public function getDateFullyDepreciated(): DateTimeImmutable
    {
        return $this->dateInService->modify('+'.$this->durationMonths.' months');
    }

    public function getCost(): int
    {
        return (int) $this->cost;
    }

    public function setCost(int $cost): static
    {
        $this->cost = (string) $cost;

        return $this;
    }

    public function getResidualValue(): int
    {
        return (int) $this->residualValue;
    }

    public function setResidualValue(int $residualValue): static
    {
        $this->residualValue = (string) $residualValue;

        return $this;
    }

    public function getDepreciableAmount(): int
    {
        return $this->getCost() - $this->getResidualValue();
    }

    public function getDurationMonths(): int
    {
        return $this->durationMonths;
    }

    public function setDurationMonths(int $durationMonths): static
    {
        if ($durationMonths <= 0) {
            throw new \InvalidArgumentException('A depreciation duration must be positive.');
        }

        $this->durationMonths = $durationMonths;

        return $this;
    }

    public function getPriorDepreciation(): int
    {
        return (int) $this->priorDepreciation;
    }

    public function setPriorDepreciation(int $priorDepreciation): static
    {
        $this->priorDepreciation = (string) $priorDepreciation;

        return $this;
    }

    public function getDateDisposed(): ?DateTimeImmutable
    {
        return $this->dateDisposed;
    }

    public function setDateDisposed(?DateTimeImmutable $dateDisposed): static
    {
        $this->dateDisposed = $dateDisposed?->setTime(0, 0);

        return $this;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): static
    {
        $this->invoice = $invoice;

        return $this;
    }
}
