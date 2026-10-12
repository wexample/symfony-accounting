<?php

namespace Wexample\SymfonyAccounting\Service\Vat;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Enum\VatRole;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\EntryLineRepository;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;
use Wexample\SymfonyAccounting\Service\Ledger\PostingService;
use Wexample\SymfonyMoney\Helper\RateHelper;

/**
 * The year's deduction made final: the VAT deducted during the year at the
 * provisional coefficient is set right with the final one, the difference posted
 * on the year's last day between deductible VAT and a gain or a loss. The final
 * coefficient becomes the next year's provisional one, unless one is already set.
 *
 * Only the coefficient is regularized: what an account's admission excludes never
 * was deductible. Multi-year regularizations of fixed assets are not handled.
 */
class VatDeductionRegularizationService
{
    public const string SOURCE = 'vat_deduction_regularization';

    public function __construct(
        private readonly EntryLineRepository $lineRepository,
        private readonly JournalEntryRepository $entryRepository,
        private readonly ChartService $chartService,
        private readonly PostingService $postingService,
    ) {
    }

    /**
     * What the year's VAT would be deductible for at the final coefficient, against
     * what was deducted: `eligible` is the VAT the coefficient applied to.
     *
     * @return array{eligible: int, deducted: int, target: int, difference: int}
     */
    public function compute(
        Ledger $ledger,
        int $year,
        int $finalRate
    ): array {
        $deductibleAccounts = [
            $this->chartService->getRoleNumber($ledger, AccountRole::VatDeductible),
            $this->chartService->getRoleNumber($ledger, AccountRole::VatDeductibleAssets),
        ];
        $deducted = 0;
        $undeducted = 0;

        foreach ($this->lineRepository->findVatLines($ledger, new DateTimeImmutable($year.'-01-01'), new DateTimeImmutable($year.'-12-31')) as $line) {
            if (VatRole::NonDeductible === $line->getVatRole()) {
                $undeducted += $line->getBalance();
            } elseif (VatRole::Tax === $line->getVatRole() && in_array($line->getAccount()->getNumber(), $deductibleAccounts, true)) {
                $deducted += $line->getBalance();
            }
        }

        $eligible = $deducted + $undeducted;
        $target = RateHelper::mulDiv($eligible, $finalRate, RateHelper::BASIS);

        return ['eligible' => $eligible, 'deducted' => $deducted, 'target' => $target, 'difference' => $target - $deducted];
    }

    public function regularize(
        Ledger $ledger,
        int $year,
        int $finalRate
    ): ?JournalEntry {
        if ([] !== $this->entryRepository->findBySource($ledger, self::SOURCE, (string) $year)) {
            throw new AccountingException(sprintf('The VAT deduction of %d is already regularized.', $year));
        }

        $difference = $this->compute($ledger, $year, $finalRate)['difference'];
        $ledger->setSettings($this->withRates($ledger, $year, $finalRate));

        if (0 === $difference) {
            return null;
        }

        $draft = (new EntryDraft(JournalType::Miscellaneous, new DateTimeImmutable($year.'-12-31'), sprintf('VAT deduction regularization %d', $year)))
            ->source(self::SOURCE, (string) $year)
            ->add(AccountRole::VatDeductible, $difference)
            ->add($difference > 0 ? AccountRole::VatAdjustmentGain : AccountRole::VatAdjustmentLoss, -$difference);

        return $this->postingService->postDraft($ledger, $draft);
    }

    /**
     * The final coefficient of the year, and the next year's provisional one.
     */
    private function withRates(
        Ledger $ledger,
        int $year,
        int $finalRate
    ): array {
        $settings = $ledger->getSettings();
        $rates = $settings['vat_deduction_rates'] ?? [];
        $rates[$year] = $finalRate;
        $rates[$year + 1] ??= $finalRate;
        $settings['vat_deduction_rates'] = $rates;

        return $settings;
    }
}
