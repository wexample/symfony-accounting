<?php

namespace Wexample\SymfonyAccounting\Service\Report;

use Wexample\SymfonyAccounting\Class\ReportDefinition;
use Wexample\SymfonyAccounting\Class\ReportResult;
use Wexample\SymfonyAccounting\Class\ReportRule;
use Wexample\SymfonyAccounting\Class\TrialBalance;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;

/**
 * Financial statements (balance sheet, income statement) from the layouts a
 * jurisdiction ships. An account is counted by the first line whose rules
 * take it, so it is never counted twice; formulas combine lines.
 */
class FinancialStatementService
{
    public function __construct(
        private readonly TrialBalanceService $trialBalanceService,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
    ) {
    }

    public function getDefinition(
        FiscalYear $fiscalYear,
        string $key
    ): ReportDefinition {
        foreach ($this->jurisdictionRegistry->forLedger($fiscalYear->getLedger())->getReportDefinitions() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        throw new AccountingException(sprintf('No report "%s" for this ledger.', $key));
    }

    /**
     * The balance sheet is read at the end of the year, closing entries included;
     * the income statement over the year.
     */
    public function build(
        FiscalYear $fiscalYear,
        string $key
    ): ReportResult {
        return $this->compute(
            $this->getDefinition($fiscalYear, $key),
            $this->trialBalanceService->build($fiscalYear->getLedger(), $fiscalYear)
        );
    }

    public function compute(
        ReportDefinition $definition,
        TrialBalance $trialBalance
    ): ReportResult {
        $balances = $trialBalance->getBalances();
        $taken = [];
        $lines = [];

        foreach ($definition->lines as $line) {
            if ($line->isFormula()) {
                continue;
            }

            $gross = 0;
            $deduct = 0;
            $accounts = [];

            foreach ($balances as $number => $balance) {
                $number = (string) $number;

                if (! isset($taken[$number]) && $this->matchesAny($line->rules, $number, $balance)) {
                    $gross += $balance * $line->sign;
                    $accounts[$number] = $balance;
                    $taken[$number] = $line->key;
                } elseif (! isset($taken[$number]) && $this->matchesAny($line->deduct, $number, $balance)) {
                    // Depreciation sits on the opposite side of the asset it reduces.
                    $deduct += -$balance * $line->sign;
                    $accounts[$number] = $balance;
                    $taken[$number] = $line->key;
                }
            }

            $lines[$line->key] = [
                'label' => $line->label,
                'level' => $line->level,
                'gross' => $gross,
                'deduct' => $deduct,
                'amount' => $gross - $deduct,
                'accounts' => $accounts,
            ];
        }

        foreach ($definition->lines as $line) {
            if ($line->isFormula()) {
                $lines[$line->key] = [
                    'label' => $line->label,
                    'level' => $line->level,
                    'gross' => 0,
                    'deduct' => 0,
                    'amount' => $this->evaluate($line->formula, $lines),
                    'accounts' => [],
                ];
            }
        }

        // Keep the definition order.
        $ordered = [];
        foreach ($definition->lines as $line) {
            $ordered[$line->key] = $lines[$line->key];
        }

        return new ReportResult($definition, $ordered);
    }

    /**
     * "sales+other-charges", "I-II+III": line keys added or subtracted, left to
     * right. Formulas may use lines computed before them.
     */
    public function evaluate(
        string $formula,
        array $lines
    ): int {
        if (! preg_match_all('/([+-]?)\s*([A-Za-z0-9_.]+)/', $formula, $terms, PREG_SET_ORDER)) {
            throw new AccountingException(sprintf('Invalid report formula "%s".', $formula));
        }

        $total = 0;

        foreach ($terms as [, $sign, $key]) {
            if (! array_key_exists($key, $lines)) {
                if (is_numeric($key)) {
                    $value = (int) $key;
                } else {
                    throw new AccountingException(sprintf('Report formula "%s" uses unknown line "%s".', $formula, $key));
                }
            } else {
                $value = $lines[$key]['amount'];
            }

            $total += '-' === $sign ? -$value : $value;
        }

        return $total;
    }

    /**
     * Accounts taken by more than one rules line, ignoring the side conditions:
     * the overlaps network's mapping had (15 ⊃ 151, 65 ⊃ 655…).
     *
     * @param list<string> $accountNumbers
     * @return array<string, list<string>> Account number → line keys.
     */
    public function findOverlaps(
        ReportDefinition $definition,
        array $accountNumbers
    ): array {
        $overlaps = [];

        foreach ($accountNumbers as $number) {
            $keys = [];

            foreach ($definition->lines as $line) {
                foreach (array_merge($line->rules, $line->deduct) as $rule) {
                    if (ReportRule::SIDE_ANY === $rule->side && $rule->matches($number, 0)) {
                        $keys[] = $line->key;
                        break;
                    }
                }
            }

            if (count($keys) > 1) {
                $overlaps[$number] = $keys;
            }
        }

        return $overlaps;
    }

    /**
     * @param list<ReportRule> $rules
     */
    private function matchesAny(
        array $rules,
        string $number,
        int $balance
    ): bool {
        foreach ($rules as $rule) {
            if ($rule->matches($number, $balance)) {
                return true;
            }
        }

        return false;
    }
}
