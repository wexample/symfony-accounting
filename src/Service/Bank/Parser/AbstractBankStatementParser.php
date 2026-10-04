<?php

namespace Wexample\SymfonyAccounting\Service\Bank\Parser;

use DateTimeImmutable;
use League\Csv\Reader;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Interface\BankStatementParserInterface;
use Wexample\SymfonyMoney\Helper\MoneyHelper;

abstract class AbstractBankStatementParser implements BankStatementParserInterface
{
    /**
     * "1.234,56", "-292,91€", "1 234.56 EUR" → minor units, decimal-comma aware.
     */
    protected function parseAmount(
        string $amount,
        string $currencyCode = 'EUR'
    ): int {
        return MoneyHelper::fromDecimal($amount, $currencyCode);
    }

    protected function parseDate(
        string $date,
        string $format
    ): DateTimeImmutable {
        $parsed = DateTimeImmutable::createFromFormat('!'.$format, trim($date));

        if (! $parsed) {
            throw new AccountingException(sprintf('"%s" is not a date of format %s.', $date, $format));
        }

        return $parsed;
    }

    /**
     * @return Reader<array<int|string, string>>
     */
    protected function createCsv(
        string $content,
        string $delimiter = ';'
    ): Reader {
        $csv = Reader::fromString($this->removeBom($content));
        $csv->setDelimiter($delimiter);

        return $csv;
    }

    protected function removeBom(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }

    /**
     * Files exported by French banks are often ISO-8859-1.
     */
    protected function toUtf8(string $content): string
    {
        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
    }

    protected function cleanLabel(string $label): string
    {
        return trim(preg_replace('/\s+/', ' ', $label));
    }
}
