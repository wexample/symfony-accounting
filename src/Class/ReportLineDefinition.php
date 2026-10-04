<?php

namespace Wexample\SymfonyAccounting\Class;

/**
 * A line of a financial statement: either accounts (rules), or a formula over
 * other lines ("I-II+III", "sales+other_income").
 *
 * The amount of a rules line is Σ balance × sign, balance being debit − credit:
 * sign 1 shows debit balances as positive (assets, charges), sign −1 credit
 * balances (liabilities, income). `deduct` rules (depreciation, provisions) are
 * subtracted from the gross amount, giving the net.
 */
final readonly class ReportLineDefinition
{
    /**
     * @param list<ReportRule> $rules
     * @param list<ReportRule> $deduct
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $rules = [],
        public ?string $formula = null,
        public int $sign = 1,
        public array $deduct = [],
        public int $level = 0,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            label: (string) ($data['label'] ?? $data['key']),
            rules: array_map(ReportRule::parse(...), $data['accounts'] ?? []),
            formula: $data['formula'] ?? null,
            sign: (int) ($data['sign'] ?? 1),
            deduct: array_map(ReportRule::parse(...), $data['deduct'] ?? []),
            level: (int) ($data['level'] ?? 0),
        );
    }

    public function isFormula(): bool
    {
        return null !== $this->formula;
    }
}
