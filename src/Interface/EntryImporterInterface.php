<?php

namespace Wexample\SymfonyAccounting\Interface;

use Wexample\SymfonyAccounting\Class\ImportedLine;

/**
 * Reads entries of another bookkeeping from a file format (FEC, CSV). It only
 * parses; EntryImportService books what it returns.
 */
interface EntryImporterInterface
{
    public function getKey(): string;

    public function getLabel(): string;

    public function supports(string $content): bool;

    /**
     * @return iterable<ImportedLine>
     */
    public function read(
        string $content,
        array $options = []
    ): iterable;
}
