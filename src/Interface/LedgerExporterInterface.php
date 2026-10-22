<?php

namespace Wexample\SymfonyAccounting\Interface;

use Wexample\SymfonyAccounting\Class\ExportFile;
use Wexample\SymfonyAccounting\Entity\FiscalYear;

/**
 * Writes the books of a fiscal year in a file format (FEC, CSV, an accounting
 * software's import format). Implementing the interface is enough to be offered.
 */
interface LedgerExporterInterface
{
    public function getKey(): string;

    public function getLabel(): string;

    public function supports(FiscalYear $fiscalYear): bool;

    public function export(
        FiscalYear $fiscalYear,
        array $options = []
    ): ExportFile;
}
