<?php

namespace Wexample\SymfonyAccounting\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Wexample\SymfonyAccounting\Entity\Traits\BelongsToLedgerTrait;
use Wexample\SymfonyAccounting\Repository\ImportPatternRepository;
use Wexample\SymfonyHelpers\Entity\AbstractEntity;

/**
 * A rule recognizing recurring bank lines by their label ("PRLV FREE MOBILE",
 * "COMMISSION") and saying how to book them: which party, which account, which
 * VAT, possibly creating the purchase document automatically.
 */
#[ORM\Entity(repositoryClass: ImportPatternRepository::class)]
#[ORM\Table(name: 'accounting_import_pattern')]
class ImportPattern extends AbstractEntity
{
    use BelongsToLedgerTrait;

    /** A PCRE pattern without delimiters, matched case-insensitively. */
    #[ORM\Column(type: Types::STRING, length: 255)]
    protected string $pattern;

    #[ORM\ManyToOne(targetEntity: Party::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Party $party = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    protected ?string $accountNumber = null;

    #[ORM\Column(type: Types::INTEGER)]
    protected int $vatRate = 0;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $label = null;

    /** Restricts the pattern to money in (1), money out (−1) or both (0). */
    #[ORM\Column(type: Types::SMALLINT)]
    protected int $sign = 0;

    /** Validate the proposal at once instead of leaving it pending. */
    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $autoValidate = false;

    #[ORM\Column(type: Types::INTEGER)]
    protected int $priority = 0;

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function setPattern(string $pattern): static
    {
        if (false === @preg_match('~'.str_replace('~', '\~', $pattern).'~i', '')) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid pattern.', $pattern));
        }

        $this->pattern = $pattern;

        return $this;
    }

    public function matches(
        string $label,
        int $amount
    ): bool {
        if ($this->sign && ($amount <=> 0) !== $this->sign) {
            return false;
        }

        return 1 === preg_match('~'.str_replace('~', '\~', $this->pattern).'~i', $label);
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

    public function getAccountNumber(): ?string
    {
        return $this->accountNumber;
    }

    public function setAccountNumber(?string $accountNumber): static
    {
        $this->accountNumber = $accountNumber;

        return $this;
    }

    public function getVatRate(): int
    {
        return $this->vatRate;
    }

    public function setVatRate(int $vatRate): static
    {
        $this->vatRate = $vatRate;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getSign(): int
    {
        return $this->sign;
    }

    public function setSign(int $sign): static
    {
        $this->sign = $sign <=> 0;

        return $this;
    }

    public function isAutoValidate(): bool
    {
        return $this->autoValidate;
    }

    public function setAutoValidate(bool $autoValidate): static
    {
        $this->autoValidate = $autoValidate;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }
}
