<?php

namespace Wexample\SymfonyAccounting\Service;

use ArrayObject;
use League\Csv\Exception;
use League\Csv\TabularDataReader;
use League\Csv\UnableToProcessCsv;
use Wexample\SymfonyAccounting\Entity\AbstractBankOrganizationEntity;
use Wexample\SymfonyHelpers\Helper\DateHelper;
use Wexample\SymfonyHelpers\Helper\FileHelper;
use Wexample\SymfonyHelpers\Helper\TextHelper;
use function explode;
use function implode;
use function in_array;
use function number_format;
use function pathinfo;
use function preg_match;
use function str_starts_with;
use function trim;

class FrLbp2019BankExportParser extends CsvWithMetadataBankExportParser
{
    public int $headerHeight = 7;

    public function parseContent(
        AbstractBankOrganizationEntity $bank,
        string $content,
        array $options = []
    ): int {
        $ext = pathinfo(
        // Allow to pass a different filename for analysis than the
        // given file path witch might be a temporary upload file name.
            $options['filename'] ?? $options['filepath']
        )['extension'];

        // PDF Files from LBP Bank are protected (with no password),
        // so pdf content can't be extracted and should be
        // copied / pasted in a txt file.
        if (FileHelper::FILE_EXTENSION_TXT === $ext) {
            return $this->convertPdfTextToTransaction($bank, $content);
        }

        return parent::parseContent($bank, $content);
    }

    /**
     * @throws Exception
     */
    public function convertPdfTextToTransaction(
        AbstractBankOrganizationEntity $bank,
        string $text
    ): int {
        $content = $this->convertPdfTextToCsv($text);

        return $this->convertCsvRecords(
            $bank,
            $this->createCsvFromBody($content)
        );
    }

    public function convertPdfTextToCsv($content): string
    {
        $exp = explode(PHP_EOL, (string) $content);
        $start = false;
        $end = false;
        $lineCount = 0;
        $csvLine = '';
        $aggregated = [];
        $price = 0;
        $year = 'n/a';

        $obj = new ArrayObject($exp);
        $it = $obj->getIterator();

        while ($line = $it->current()) {
            // Ignore useless lines.
            if (in_array(
                trim($line),
                [
                    'Crédit (¤)',
                    'Débit (¤)',
                ]
            )) {
                $it->next();
                continue;
            }

            if ($start) {
                // Reached end point.
                if (str_starts_with(trim($line), 'Total des opérations')) {
                    $end = true;
                }

                if (!$end) {
                    ++$lineCount;

                    $matches = [];

                    // Detect first part.
                    if (preg_match(
                        '/^(\d\d\/\d\d)\s?(.*)/',
                        $line,
                        $matches
                    )) {
                        // There is an unfinished previous line.
                        if ($csvLine) {
                            // Append it.
                            $aggregated[] = $csvLine.'";'.number_format(
                                    $price,
                                    2,
                                    ',',
                                    ''
                                );
                        }

                        // Start a new line.
                        $price = 0;
                        $csvLine = $matches[1].'/'.$year.';"';

                        // Date may have label starting just after it
                        if ($matches[2]) {
                            $csvLine .= $matches[2];
                        }
                    } elseif (preg_match(
                        '/^([0-9\s]*,\d\d).*/',
                        $line,
                        $matches
                    )) {
                        $price = TextHelper::getFloatFromString($matches[1]);

                        // This is an output.
                        if (preg_match(
                                '/^\d\d\/\d\d\/\d\d\d\d.*VIREMENT POUR/',
                                $csvLine,
                                $matches
                            ) || preg_match(
                                '/^\d\d\/\d\d\/\d\d\d\d.*Cotisation Adispo Ass Integral/',
                                $csvLine,
                                $matches
                            )) {
                            $price = 0 - $price;
                        }
                    } else {
                        $csvLine .= $line;
                    }
                }
            } // Not started yet (not in the list body)
            else {
                $matches = [];

                // Find the year.
                if (preg_match(
                    '/Arrêté mensuel du.*(\d\d\d\d)$/',
                    $line,
                    $matches
                )) {
                    $year = $matches[1];
                }
            }

            // Find starting point.
            if (str_starts_with(trim($line), 'Ancien solde au ')) {
                $start = true;
            }

            $it->next();
        }

        $aggregated[] = $csvLine.'";'.$price;

        return implode(PHP_EOL, $aggregated);
    }

    protected function getDateExport(TabularDataReader $header): ?string
    {
        return $header->fetchOne(3)[1];
    }

    protected function getDateFormat(): string
    {
        return DateHelper::DATE_PATTERN_YMD_FR;
    }

    /**
     * @throws UnableToProcessCsv
     */
    protected function getAccountBalanceStatement(TabularDataReader $header): ?int
    {
        return TextHelper::getIntDataFromString($header->fetchOne(4)[1]);
    }

    protected function getRecordDescription(array $record): string
    {
        return $record[1];
    }

    protected function getRecordDateString(array $record): string
    {
        return $record[0];
    }

    protected function getRecordAmountString(array $record): string
    {
        return $record[2];
    }
}
