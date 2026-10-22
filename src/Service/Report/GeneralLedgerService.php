<?php

namespace Wexample\SymfonyAccounting\Service\Report;

use DateTimeInterface;
use Wexample\SymfonyAccounting\Entity\EntryLine;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Repository\EntryLineRepository;

/**
 * The general ledger (grand livre): every line of every account, with a running
 * balance. Lines of party accounts can be split per auxiliary account, the way
 * accountants read customers and suppliers.
 */
class GeneralLedgerService
{
    public function __construct(
        private readonly EntryLineRepository $lineRepository,
    ) {
    }

    /**
     * @return array<string, array{number: string, label: string, auxiliary: ?string, lines: list<array{line: EntryLine, balance: int}>, debit: int, credit: int, balance: int}>
     *         Keyed by account number, or number + "/" + auxiliary code.
     */
    public function build(
        Ledger $ledger,
        ?FiscalYear $fiscalYear = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        bool $byAuxiliary = false,
        ?string $accountPrefix = null,
    ): array {
        $accounts = [];

        foreach ($this->lineRepository->findForAccount($ledger, fiscalYear: $fiscalYear, from: $from, to: $to, accountPrefix: $accountPrefix) as $line) {
            $number = $line->getAccount()->getNumber();
            $auxiliary = $byAuxiliary && $line->getAccount()->isLettrable() ? $line->getAuxiliaryCode() : null;
            $key = $number.(null !== $auxiliary ? '/'.$auxiliary : '');

            $accounts[$key] ??= [
                'number' => $number,
                'label' => $line->getAccount()->getLabel(),
                'auxiliary' => $auxiliary,
                'lines' => [],
                'debit' => 0,
                'credit' => 0,
                'balance' => 0,
            ];

            $accounts[$key]['debit'] += $line->getDebit();
            $accounts[$key]['credit'] += $line->getCredit();
            $accounts[$key]['balance'] += $line->getBalance();
            $accounts[$key]['lines'][] = ['line' => $line, 'balance' => $accounts[$key]['balance']];
        }

        ksort($accounts, SORT_STRING);

        return $accounts;
    }
}
