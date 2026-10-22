<?php

namespace Wexample\SymfonyAccounting\Class;

/**
 * The amounts of a financial statement, line by line.
 */
final readonly class ReportResult
{
    /**
     * @param array<string, array{label: string, level: int, gross: int, deduct: int, amount: int, accounts: array<string, int>}> $lines
     */
    public function __construct(
        public ReportDefinition $definition,
        public array $lines,
    ) {
    }

    public function get(string $key): int
    {
        return $this->lines[$key]['amount'] ?? 0;
    }

    public function toArray(): array
    {
        return [
            'key' => $this->definition->key,
            'label' => $this->definition->label,
            'lines' => $this->lines,
        ];
    }
}
