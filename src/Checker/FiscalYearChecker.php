<?php

namespace Wexample\SymfonyAccounting\Checker;

use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyAccounting\Repository\BankAccountRepository;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyAccounting\Service\Bank\ReconciliationService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceAccountingService;
use Wexample\SymfonyAccounting\Service\Report\TrialBalanceService;
use Wexample\SymfonyCheck\Class\Finding;
use Wexample\SymfonyCheck\Interface\CheckerInterface;

/**
 * What must hold before a year is closed. Errors block the closing; warnings
 * are worth a look first.
 */
class FiscalYearChecker implements CheckerInterface
{
    public const string DOMAIN = 'accounting_check';

    public function __construct(
        private readonly JournalEntryRepository $entryRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly InvoiceAccountingService $invoiceAccountingService,
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly BankTransactionRepository $transactionRepository,
        private readonly ReconciliationService $reconciliationService,
        private readonly TrialBalanceService $trialBalanceService,
    ) {
    }

    public function supports(object $subject): bool
    {
        return $subject instanceof FiscalYear;
    }

    /**
     * @param FiscalYear $subject
     */
    public function check(object $subject): iterable
    {
        $fiscalYear = $subject;
        $ledger = $fiscalYear->getLedger();

        if (($drafts = $this->entryRepository->countDrafts($fiscalYear)) > 0) {
            yield Finding::error('fiscal_year.draft_entries', ['count' => $drafts], self::DOMAIN);
        }

        if (! $this->trialBalanceService->build($ledger, $fiscalYear)->isBalanced()) {
            yield Finding::error('fiscal_year.unbalanced', [], self::DOMAIN);
        }

        $notBooked = 0;
        $notEmitted = 0;

        foreach ($this->invoiceRepository->findForPeriod($ledger, $fiscalYear->getDateStart(), $fiscalYear->getDateEnd()) as $invoice) {
            if ($invoice->getStatus()->isEmitted()) {
                if ($invoice->getType()->isAccountable() && ! $this->invoiceAccountingService->findEntry($invoice)) {
                    ++$notBooked;
                }
            } elseif ($invoice->getType()->isAccountable() && $invoice->isEditable() && ! $invoice->getItems()->isEmpty()) {
                ++$notEmitted;
            }
        }

        if ($notBooked > 0) {
            yield Finding::error('fiscal_year.documents_not_booked', ['count' => $notBooked], self::DOMAIN);
        }

        if ($notEmitted > 0) {
            yield Finding::warning('fiscal_year.documents_not_emitted', ['count' => $notEmitted], self::DOMAIN);
        }

        foreach ($this->bankAccountRepository->findBy(['ledger' => $ledger]) as $bankAccount) {
            $unsettled = 0;
            $pending = 0;

            foreach ($this->transactionRepository->findForPeriod($bankAccount, $fiscalYear->getDateStart(), $fiscalYear->getDateEnd()) as $transaction) {
                if (! $transaction->isSettled() && ! $this->transactionRepository->isTransferTarget($transaction)) {
                    ++$unsettled;
                }

                foreach ($transaction->getAllocations() as $allocation) {
                    $pending += (int) (AllocationStatus::Pending === $allocation->getStatus());
                }
            }

            if ($unsettled > 0) {
                yield Finding::warning('fiscal_year.bank_lines_unexplained', ['account' => $bankAccount->getLabel(), 'count' => $unsettled], self::DOMAIN);
            }

            if ($pending > 0) {
                yield Finding::warning('fiscal_year.allocations_to_validate', ['account' => $bankAccount->getLabel(), 'count' => $pending], self::DOMAIN);
            }

            $bankBalance = $this->reconciliationService->getBalanceAt($bankAccount, $fiscalYear->getDateEnd());
            $bookBalance = $this->reconciliationService->getBookBalanceAt($bankAccount, $fiscalYear->getDateEnd());

            if (null !== $bankBalance && $bankBalance !== $bookBalance) {
                yield Finding::warning('fiscal_year.bank_not_reconciled', [
                    'account' => $bankAccount->getLabel(),
                    'bank' => $bankBalance,
                    'books' => $bookBalance,
                ], self::DOMAIN);
            }
        }
    }
}
