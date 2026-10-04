<?php

namespace Wexample\SymfonyAccounting\Service\Vat;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Class\VatReturn;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Enum\VatRole;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Interface\VatReturnFormInterface;
use Wexample\SymfonyAccounting\Repository\EntryLineRepository;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;
use Wexample\SymfonyAccounting\Service\Ledger\PostingService;
use Wexample\SymfonyMoney\Helper\RateHelper;

/**
 * VAT returns, computed from the entry lines tagged with VAT codes.
 *
 * Tax is counted on the accounts where it is due or deductible, not on the
 * pending ones: under VAT on payments, it reaches them when the payment is
 * booked, so the same computation serves both regimes. Bases follow the same
 * rule: on payments, the base of a taxed code is the tax divided by its rate.
 *
 * Settling posts the entry that empties the VAT accounts into what is payable,
 * or into a credit carried to the next return.
 */
class VatReturnService
{
    public const string SOURCE_SETTLEMENT = 'vat_settlement';

    /**
     * @param iterable<VatReturnFormInterface> $forms
     */
    public function __construct(
        private readonly EntryLineRepository $lineRepository,
        private readonly JournalEntryRepository $entryRepository,
        private readonly ChartService $chartService,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
        private readonly PostingService $postingService,
        private readonly iterable $forms = [],
    ) {
    }

    public function compute(
        Ledger $ledger,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): VatReturn {
        $jurisdiction = $this->jurisdictionRegistry->forLedger($ledger);
        $vatReturn = new VatReturn($ledger, $from->setTime(0, 0), $to->setTime(0, 0));
        $dueAccounts = $this->roleNumbers($ledger, [AccountRole::VatCollected, AccountRole::VatSelfAssessed]);
        $deductibleAccounts = $this->roleNumbers($ledger, [AccountRole::VatDeductible, AccountRole::VatDeductibleAssets]);
        $onPayments = $ledger->isVatOnPayments();

        foreach ($this->lineRepository->findVatLines($ledger, $from, $to) as $line) {
            $code = $jurisdiction->getVatCode($line->getVatCode());
            $returnLine = $vatReturn->getLine($line->getVatCode(), $code?->direction, $code?->kind, $code?->rate ?? 0);
            $number = $line->getAccount()->getNumber();

            if (VatRole::Base === $line->getVatRole()) {
                // On payments, a taxed base is derived from the tax once due.
                if ($onPayments && $code?->isCharged()) {
                    continue;
                }

                $base = InvoiceDirection::Sale === $code?->direction ? -$line->getBalance() : $line->getBalance();
                $returnLine->base += $base;
                $returnLine->baseReversed += max(0, -$base);
                $returnLine->accountBases[$number] = ($returnLine->accountBases[$number] ?? 0) + $base;
            } elseif (in_array($number, $dueAccounts, true)) {
                $returnLine->due += -$line->getBalance();
                $returnLine->dueReversed += max(0, $line->getBalance());
            } elseif (in_array($number, $deductibleAccounts, true)) {
                $returnLine->deductible += $line->getBalance();
                $returnLine->deductibleReversed += max(0, -$line->getBalance());
            }
        }

        if ($onPayments) {
            foreach ($vatReturn->lines as $returnLine) {
                $code = $jurisdiction->getVatCode($returnLine->code);

                if ($code?->isCharged() && $returnLine->rate > 0) {
                    $tax = InvoiceDirection::Sale === $code->direction ? $returnLine->due : $returnLine->deductible;
                    $returnLine->base = RateHelper::mulDiv($tax, RateHelper::BASIS, $returnLine->rate);
                }
            }
        }

        $vatReturn->previousCredit = max(0, $this->balanceBefore($ledger, AccountRole::VatCredit, $from));
        $vatReturn->deposits = max(0, $this->periodBalance($ledger, AccountRole::VatDeposits, $from, $to));

        return $vatReturn;
    }

    /**
     * Books the return: empties due and deductible VAT, the credit carried and the
     * deposits, into VAT payable or a new VAT credit.
     */
    public function settle(
        VatReturn $vatReturn,
        ?DateTimeImmutable $date = null
    ): JournalEntry {
        $ledger = $vatReturn->ledger;
        $period = $vatReturn->from->format('Y-m-d').'_'.$vatReturn->to->format('Y-m-d');

        if ([] !== $this->entryRepository->findBySource($ledger, self::SOURCE_SETTLEMENT, $period)) {
            throw new AccountingException('This VAT period is already settled.');
        }

        $draft = (new EntryDraft(JournalType::Miscellaneous, $date ?? $vatReturn->to, 'VAT return '.$vatReturn->from->format('Y-m-d').' → '.$vatReturn->to->format('Y-m-d')))
            ->source(self::SOURCE_SETTLEMENT, $period);

        $collected = $this->chartService->getRoleNumber($ledger, AccountRole::VatCollected);
        $selfAssessed = $this->chartService->getRoleNumber($ledger, AccountRole::VatSelfAssessed);
        $deductible = $this->chartService->getRoleNumber($ledger, AccountRole::VatDeductible);
        $jurisdiction = $this->jurisdictionRegistry->forLedger($ledger);

        foreach ($vatReturn->lines as $line) {
            $code = $jurisdiction->getVatCode($line->code);
            $dueAccount = $code?->isSelfAssessed() ? $selfAssessed : $collected;
            $draft->add($dueAccount, $line->due);
            $draft->add($deductible, -$line->deductible);
        }

        $draft->add(AccountRole::VatCredit, -$vatReturn->previousCredit);
        $draft->add(AccountRole::VatDeposits, -$vatReturn->deposits);

        $net = $vatReturn->getNet();
        $draft->add($net > 0 ? AccountRole::VatPayable : AccountRole::VatCredit, -$net);

        if ($draft->isEmpty()) {
            throw new AccountingException('Nothing to settle for this VAT period.');
        }

        return $this->postingService->postDraft($ledger, $draft);
    }

    /**
     * The national forms of the ledger, filled.
     *
     * @return array<string, array<string, int>> Form key → boxes.
     */
    public function fillForms(VatReturn $vatReturn): array
    {
        $filled = [];

        foreach ($this->forms as $form) {
            if ($form->supports($vatReturn->ledger)) {
                $filled[$form->getKey()] = $form->fill($vatReturn);
            }
        }

        return $filled;
    }

    /**
     * @param list<AccountRole> $roles
     * @return list<string>
     */
    private function roleNumbers(
        Ledger $ledger,
        array $roles
    ): array {
        $numbers = [];

        foreach ($roles as $role) {
            try {
                $numbers[] = $this->chartService->getRoleNumber($ledger, $role);
            } catch (AccountingException) {
            }
        }

        return $numbers;
    }

    private function balanceBefore(
        Ledger $ledger,
        AccountRole $role,
        DateTimeImmutable $before
    ): int {
        return $this->periodBalance($ledger, $role, null, $before->modify('-1 day'));
    }

    private function periodBalance(
        Ledger $ledger,
        AccountRole $role,
        ?DateTimeImmutable $from,
        DateTimeImmutable $to
    ): int {
        $number = $this->chartService->getRoleNumber($ledger, $role);
        $sums = $this->lineRepository->sumByAccount($ledger, $from, $to);

        return isset($sums[$number]) ? $sums[$number]['debit'] - $sums[$number]['credit'] : 0;
    }
}
