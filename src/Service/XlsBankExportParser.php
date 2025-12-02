<?php

namespace Wexample\SymfonyAccounting\Service;

use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Row;
use Wexample\Helpers\Helper\TextHelper;
use Wexample\SymfonyAccounting\Entity\AbstractAccountingTransactionEntity;
use Wexample\SymfonyAccounting\Entity\AbstractBankOrganizationEntity;
use Wexample\SymfonyHelpers\Helper\DateHelper;
use Wexample\SymfonyHelpers\Helper\PriceHelper;

abstract class XlsBankExportParser extends AbstractBankExportParser
{
    public int $headerHeight;

    public function parseFile(
        AbstractBankOrganizationEntity $bank,
        string $filePath,
        array $options = []
    ): int {
        $options['filepath'] = $filePath;
        $spreadsheet = IOFactory::load($filePath);

        return $this->parseContent(
            $bank,
            $spreadsheet,
            $options
        );
    }

    /**
     * @param Spreadsheet                    $content
     * @throws Exception
     */
    public function parseContent(
        AbstractBankOrganizationEntity $bank,
        $content,
        array $options = []
    ): int {
        $worksheet = $content->getActiveSheet();

        // Load header.
        $value = $worksheet->getCell('A1')->getValue();
        // Use regex to extract the date
        preg_match('/(\d{2}\/\d{2}\/\d{4})/', $value, $matches);
        $created = $matches[1] ?? null;

        $balanceStatement = PriceHelper::priceToInt(
            $worksheet->getCell('C7')->getValue()
        );

        if ($created and $balanceStatement) {
            $dateCreated = $this->parseDate($created, $this->getDateFormat());

            $this->saveTransactionOfNotExists(
                $bank,
                $dateCreated,
                AbstractAccountingTransactionEntity::TYPE_STATEMENT.' - '
                .$dateCreated->format(DateHelper::DATE_PATTERN_DAY_DEFAULT),
                $balanceStatement
            );
        }

        $rowIterator = $worksheet->getRowIterator(5);
        $rowIterator->resetStart($this->headerHeight);
        $count = 0;

        foreach ($rowIterator as $row) {
            $desc = trim(
                $this->getRowDescription($row)
            );
            $date = $this->parseDate(
                $this->getRowDateString($row),
                $this->getDateFormat()
            );
            $amount = TextHelper::getIntDataFromString(
                $this->getRowAmountString($row)
            );

            if ($this->saveTransactionOfNotExists(
                $bank,
                $date,
                $desc,
                $amount
            )) {
                ++$count;
            }
        }

        return $count;
    }

    abstract protected function getDateFormat(): string;

    abstract protected function getRowDescription(Row $row): string;

    abstract protected function getRowDateString(Row $row): string;

    abstract protected function getRowAmountString(Row $row): string;

    protected function printRowAsString(Row $row): string
    {
        $values = [];
        foreach ($row->getCellIterator() as $cell) {
            $values[] = $cell->getValue();
        }

        return implode(', ', $values).PHP_EOL;
    }
}
