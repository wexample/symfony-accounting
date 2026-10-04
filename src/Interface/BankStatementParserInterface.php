<?php

namespace Wexample\SymfonyAccounting\Interface;

use Wexample\SymfonyAccounting\Class\ParsedStatement;

/**
 * Reads one bank file format. Parsers never touch the database: BankImportService
 * stores what they return, deduplicating it. Implementing the interface is enough
 * to be offered in imports.
 */
interface BankStatementParserInterface
{
    /**
     * Unique among parsers: "camt053", "ofx", "csv", "stripe_csv", "fr_lbp_2023"…
     */
    public function getKey(): string;

    public function getLabel(): string;

    /**
     * Whether the content looks like this format, to pick a parser automatically.
     */
    public function supports(
        string $content,
        ?string $filename = null
    ): bool;

    /**
     * @param array<string, mixed> $options Format-specific settings.
     */
    public function parse(
        string $content,
        array $options = []
    ): ParsedStatement;
}
