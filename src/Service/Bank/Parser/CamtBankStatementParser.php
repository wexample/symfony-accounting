<?php

namespace Wexample\SymfonyAccounting\Service\Bank\Parser;

use SimpleXMLElement;
use Wexample\SymfonyAccounting\Class\ParsedBalance;
use Wexample\SymfonyAccounting\Class\ParsedStatement;
use Wexample\SymfonyAccounting\Class\ParsedTransaction;
use Wexample\SymfonyAccounting\Exception\AccountingException;

/**
 * ISO 20022 cash management statements: camt.053 (end of day), camt.052
 * (intraday) and camt.054 (notifications), as most European banks export them,
 * Belgian and French ones included.
 *
 * One booking entry (Ntry) becomes one line; its structured reference (Strd)
 * and the counterparty are kept for matching. The closing booked balance
 * (CLBD) becomes a stated balance.
 */
class CamtBankStatementParser extends AbstractBankStatementParser
{
    public function getKey(): string
    {
        return 'camt';
    }

    public function getLabel(): string
    {
        return 'ISO 20022 CAMT (053, 052, 054)';
    }

    public function supports(
        string $content,
        ?string $filename = null
    ): bool {
        return str_contains(substr($content, 0, 2000), 'urn:iso:std:iso:20022:tech:xsd:camt.05');
    }

    public function parse(
        string $content,
        array $options = []
    ): ParsedStatement {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($this->removeBom($content));
        libxml_use_internal_errors($previous);

        if (false === $xml) {
            throw new AccountingException('The CAMT file is not valid XML.');
        }

        $statement = new ParsedStatement();
        $reports = $this->xpath($xml, '//*[local-name()="Stmt" or local-name()="Rpt" or local-name()="Ntfctn"]');

        foreach ($reports as $report) {
            $statement->iban ??= $this->text($report, './*[local-name()="Acct"]/*[local-name()="Id"]/*[local-name()="IBAN"]');
            $statement->currencyCode ??= $this->text($report, './*[local-name()="Acct"]/*[local-name()="Ccy"]');

            foreach ($this->xpath($report, './*[local-name()="Bal"]') as $balance) {
                $type = $this->text($balance, './/*[local-name()="Cd"]');

                if ('CLBD' === $type) {
                    $statement->addBalance(new ParsedBalance(
                        $this->parseDate(substr((string) $this->text($balance, './*[local-name()="Dt"]/*'), 0, 10), 'Y-m-d'),
                        $this->signedAmount($balance)
                    ));
                }
            }

            foreach ($this->xpath($report, './*[local-name()="Ntry"]') as $entry) {
                $statement->addTransaction($this->parseEntry($entry));
            }
        }

        return $statement;
    }

    private function parseEntry(SimpleXMLElement $entry): ParsedTransaction
    {
        $credit = 'CRDT' === $this->text($entry, './*[local-name()="CdtDbtInd"]');
        $details = $this->xpath($entry, './*[local-name()="NtryDtls"]/*[local-name()="TxDtls"]')[0] ?? null;
        $party = $credit ? 'Dbtr' : 'Cdtr';

        $unstructured = $details ? $this->texts($details, './*[local-name()="RmtInf"]/*[local-name()="Ustrd"]') : [];
        $structured = $details ? $this->text($details, './/*[local-name()="CdtrRefInf"]/*[local-name()="Ref"]') : null;
        $label = implode(' ', $unstructured)
            ?: (string) $this->text($entry, './*[local-name()="AddtlNtryInf"]')
            ?: (string) $this->text($entry, './/*[local-name()="AddtlTxInf"]');

        $counterpartyName = $details ? ($this->text($details, './*[local-name()="RltdPties"]/*[local-name()="'.$party.'"]//*[local-name()="Nm"]')) : null;
        $counterpartyIban = $details ? $this->text($details, './*[local-name()="RltdPties"]/*[local-name()="'.$party.'Acct"]//*[local-name()="IBAN"]') : null;
        $date = $this->text($entry, './*[local-name()="BookgDt"]/*') ?? $this->text($entry, './*[local-name()="ValDt"]/*');
        $valueDate = $this->text($entry, './*[local-name()="ValDt"]/*');

        return new ParsedTransaction(
            date: $this->parseDate(substr((string) $date, 0, 10), 'Y-m-d'),
            amount: $this->signedAmount($entry),
            label: $this->cleanLabel(trim(($counterpartyName ? $counterpartyName.' ' : '').$label)) ?: 'Bank operation',
            externalId: $this->text($entry, './*[local-name()="AcctSvcrRef"]')
                ?? ($details ? $this->text($details, './/*[local-name()="AcctSvcrRef"]') : null),
            valueDate: $valueDate ? $this->parseDate(substr($valueDate, 0, 10), 'Y-m-d') : null,
            counterpartyName: $counterpartyName,
            counterpartyIban: $counterpartyIban,
            reference: $structured
                ?? ($details ? $this->nonEmpty($this->text($details, './/*[local-name()="EndToEndId"]')) : null),
        );
    }

    private function signedAmount(SimpleXMLElement $node): int
    {
        $amountNode = $this->xpath($node, './*[local-name()="Amt"]')[0] ?? null;
        $amount = $this->parseAmount((string) $amountNode, (string) ($amountNode['Ccy'] ?? 'EUR'));
        $credit = 'CRDT' === $this->text($node, './*[local-name()="CdtDbtInd"]');

        return $credit ? $amount : -$amount;
    }

    private function nonEmpty(?string $value): ?string
    {
        return null === $value || 'NOTPROVIDED' === strtoupper($value) ? null : $value;
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function xpath(
        SimpleXMLElement $node,
        string $path
    ): array {
        return $node->xpath($path) ?: [];
    }

    private function text(
        SimpleXMLElement $node,
        string $path
    ): ?string {
        $found = $this->xpath($node, $path)[0] ?? null;
        $value = null === $found ? null : trim((string) $found);

        return '' === $value ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function texts(
        SimpleXMLElement $node,
        string $path
    ): array {
        return array_values(array_filter(array_map(fn ($found) => trim((string) $found), $this->xpath($node, $path))));
    }
}
