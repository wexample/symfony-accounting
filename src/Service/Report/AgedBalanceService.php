<?php

namespace Wexample\SymfonyAccounting\Service\Report;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Repository\EntryLineRepository;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;

/**
 * The aged balance (balance âgée): what each customer owes (or the ledger owes
 * each supplier), by how late it is. Lettered lines are settled and left out.
 */
class AgedBalanceService
{
    public const array BUCKETS = ['not_due' => null, '0_30' => 30, '31_60' => 60, '61_90' => 90, 'over_90' => PHP_INT_MAX];

    public function __construct(
        private readonly EntryLineRepository $lineRepository,
        private readonly ChartService $chartService,
    ) {
    }

    /**
     * @return array<string, array{party: string, total: int, buckets: array<string, int>}>
     *         Keyed by auxiliary code. Amounts are positive when owed to the ledger
     *         (customers) or by it (suppliers).
     */
    public function build(
        Ledger $ledger,
        AccountRole $role = AccountRole::Customers,
        ?DateTimeImmutable $at = null
    ): array {
        $at = ($at ?? new DateTimeImmutable())->setTime(0, 0);
        $prefix = $this->chartService->getRoleNumber($ledger, $role);
        $sign = AccountRole::Suppliers === $role ? -1 : 1;
        $result = [];

        foreach ($this->lineRepository->findForAccount($ledger, to: $at, accountPrefix: $prefix) as $line) {
            if ($line->getLetter()) {
                continue;
            }

            $key = $line->getAuxiliaryCode() ?? $line->getAccount()->getNumber();
            $result[$key] ??= [
                'party' => $line->getParty()?->getName() ?? $line->getAccount()->getLabel(),
                'total' => 0,
                'buckets' => array_fill_keys(array_keys(self::BUCKETS), 0),
            ];

            $amount = $sign * $line->getBalance();
            $due = $line->getDateDue() ?? $line->getEntry()->getDate();
            $late = $due >= $at ? null : (int) $due->diff($at)->days;

            $result[$key]['total'] += $amount;
            $result[$key]['buckets'][$this->bucket($late)] += $amount;
        }

        ksort($result);

        return array_filter($result, fn (array $row) => 0 !== $row['total']);
    }

    private function bucket(?int $daysLate): string
    {
        if (null === $daysLate) {
            return 'not_due';
        }

        foreach (self::BUCKETS as $key => $limit) {
            if (null !== $limit && $daysLate <= $limit) {
                return $key;
            }
        }

        return 'over_90';
    }
}
