<?php

namespace Wexample\SymfonyAccounting\Service\Bank\Parser;

use Wexample\SymfonyAccounting\Class\ParsedBalance;
use Wexample\SymfonyAccounting\Class\ParsedStatement;
use Wexample\SymfonyAccounting\Class\ParsedTransaction;

/**
 * OFX, versions 1 (SGML, leaf tags left open) and 2 (XML), which most French
 * banks offer. FITID becomes the external id.
 */
class OfxBankStatementParser extends AbstractBankStatementParser
{
    public function getKey(): string
    {
        return 'ofx';
    }

    public function getLabel(): string
    {
        return 'OFX';
    }

    public function supports(
        string $content,
        ?string $filename = null
    ): bool {
        $head = strtoupper(substr($content, 0, 1000));

        return str_contains($head, 'OFXHEADER') || str_contains($head, '<OFX>');
    }

    public function parse(
        string $content,
        array $options = []
    ): ParsedStatement {
        $content = $this->toUtf8($content);
        $currency = $this->tag($content, 'CURDEF') ?? 'EUR';
        $statement = new ParsedStatement($this->tag($content, 'ACCTID'), $currency);

        preg_match_all('/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>)|(?=<\/BANKTRANLIST>))/si', $content, $blocks);

        foreach ($blocks[1] as $block) {
            $name = $this->tag($block, 'NAME');
            $memo = $this->tag($block, 'MEMO');

            $statement->addTransaction(new ParsedTransaction(
                date: $this->parseOfxDate((string) $this->tag($block, 'DTPOSTED')),
                amount: $this->parseAmount((string) $this->tag($block, 'TRNAMT'), $currency),
                label: $this->cleanLabel(implode(' ', array_unique(array_filter([$name, $memo])))) ?: 'Bank operation',
                externalId: $this->tag($block, 'FITID'),
                counterpartyName: $name,
                reference: $this->tag($block, 'REFNUM') ?? $this->tag($block, 'CHECKNUM'),
                metadata: ['ofx_type' => $this->tag($block, 'TRNTYPE')],
            ));
        }

        if (preg_match('/<LEDGERBAL>(.*?)(?:<\/LEDGERBAL>|(?=<AVAILBAL>)|$)/si', $content, $ledger)) {
            $amount = $this->tag($ledger[1], 'BALAMT');
            $date = $this->tag($ledger[1], 'DTASOF');

            if (null !== $amount && null !== $date) {
                $statement->addBalance(new ParsedBalance($this->parseOfxDate($date), $this->parseAmount($amount, $currency)));
            }
        }

        return $statement;
    }

    private function tag(
        string $content,
        string $tag
    ): ?string {
        if (! preg_match('/<'.$tag.'>([^<\r\n]*)/i', $content, $matches)) {
            return null;
        }

        $value = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));

        return '' === $value ? null : $value;
    }

    /**
     * "20260115", "20260115120000[+1:CET]".
     */
    private function parseOfxDate(string $date): \DateTimeImmutable
    {
        return $this->parseDate(substr($date, 0, 8), 'Ymd');
    }
}
