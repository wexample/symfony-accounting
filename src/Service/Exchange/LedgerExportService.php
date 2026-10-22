<?php

namespace Wexample\SymfonyAccounting\Service\Exchange;

use Wexample\SymfonyAccounting\Class\ExportFile;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Interface\LedgerExporterInterface;

class LedgerExportService
{
    /**
     * @param iterable<LedgerExporterInterface> $exporters
     */
    public function __construct(
        private readonly iterable $exporters = [],
    ) {
    }

    /**
     * @return array<string, string> Key → label, for this fiscal year.
     */
    public function getChoices(FiscalYear $fiscalYear): array
    {
        $choices = [];

        foreach ($this->exporters as $exporter) {
            if ($exporter->supports($fiscalYear)) {
                $choices[$exporter->getKey()] = $exporter->getLabel();
            }
        }

        return $choices;
    }

    public function export(
        FiscalYear $fiscalYear,
        string $key,
        array $options = []
    ): ExportFile {
        foreach ($this->exporters as $exporter) {
            if ($exporter->getKey() === $key && $exporter->supports($fiscalYear)) {
                return $exporter->export($fiscalYear, $options);
            }
        }

        throw new AccountingException(sprintf('No exporter "%s" for this fiscal year.', $key));
    }
}
