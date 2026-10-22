<?php

namespace Wexample\SymfonyAccounting\Class;

/**
 * A financial statement layout (balance sheet, income statement), shipped as data
 * by a jurisdiction.
 */
final readonly class ReportDefinition
{
    /**
     * @param list<ReportLineDefinition> $lines
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $lines,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            label: (string) ($data['label'] ?? $data['key']),
            lines: array_map(ReportLineDefinition::fromArray(...), $data['lines'] ?? []),
        );
    }

    public function getLine(string $key): ?ReportLineDefinition
    {
        foreach ($this->lines as $line) {
            if ($line->key === $key) {
                return $line;
            }
        }

        return null;
    }
}
