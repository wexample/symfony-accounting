<?php

namespace Wexample\SymfonyAccounting\Service\Asset;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Entity\FixedAsset;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\FixedAssetRepository;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;
use Wexample\SymfonyAccounting\Service\Ledger\PostingService;
use Wexample\SymfonyMoney\Helper\RateHelper;

/**
 * Straight-line depreciation, pro rata temporis by day: over its useful life, an
 * asset loses its depreciable amount in proportion to the days elapsed. The
 * amount of a period is what is depreciated at its end minus at its start, so
 * the periods always add up to the exact amount.
 *
 * A fiscal year's depreciation is booked once per asset (expense against the
 * depreciation account); booking twice changes nothing.
 */
class DepreciationService
{
    public const string SOURCE = 'depreciation';
    public const string SOURCE_DISPOSAL = 'asset_disposal';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FixedAssetRepository $assetRepository,
        private readonly JournalEntryRepository $entryRepository,
        private readonly PostingService $postingService,
        private readonly ChartService $chartService,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
    ) {
    }

    /**
     * An asset from the purchase line that bought it, its cost being the line's net.
     */
    public function createFromInvoiceItem(
        InvoiceItem $item,
        int $durationMonths,
        ?DateTimeImmutable $dateInService = null
    ): FixedAsset {
        $invoice = $item->getInvoice();

        if (! $item->getAccountNumber() || ! str_starts_with($item->getAccountNumber(), '2')) {
            throw new AccountingException('The line must be booked on a fixed asset account (class 2).');
        }

        $asset = (new FixedAsset())
            ->setLedger($invoice->getLedger())
            ->setLabel($item->getTitle())
            ->setAccountNumber($item->getAccountNumber())
            ->setCost($item->calcPriceNet())
            ->setDurationMonths($durationMonths)
            ->setDateInService($dateInService ?? $invoice->getDateInvoice())
            ->setInvoice($invoice);

        $this->entityManager->persist($asset);
        $this->entityManager->flush();

        return $asset;
    }

    /**
     * Depreciation accumulated from the start of service to the end of a day.
     */
    public function accumulatedAt(
        FixedAsset $asset,
        DateTimeImmutable $date
    ): int {
        $start = $asset->getDateInService();
        $end = $asset->getDateFullyDepreciated();
        $until = $date->setTime(0, 0)->modify('+1 day');

        if ($asset->getDateDisposed() && $asset->getDateDisposed() < $until) {
            $until = $asset->getDateDisposed()->modify('+1 day');
        }

        if ($until <= $start) {
            return 0;
        }

        if ($until >= $end) {
            return $asset->getDepreciableAmount();
        }

        $totalDays = (int) $start->diff($end)->days;
        $elapsed = (int) $start->diff($until)->days;

        return RateHelper::mulDiv($asset->getDepreciableAmount(), $elapsed, $totalDays);
    }

    public function amountForPeriod(
        FixedAsset $asset,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): int {
        return $this->accumulatedAt($asset, $to) - $this->accumulatedAt($asset, $from->modify('-1 day'));
    }

    /**
     * @return list<array{from: DateTimeImmutable, to: DateTimeImmutable, amount: int, accumulated: int}> The depreciation plan by calendar year.
     */
    public function schedule(FixedAsset $asset): array
    {
        $plan = [];
        $year = (int) $asset->getDateInService()->format('Y');
        $lastYear = (int) $asset->getDateFullyDepreciated()->format('Y');

        for (; $year <= $lastYear; ++$year) {
            $from = new DateTimeImmutable($year.'-01-01');
            $to = new DateTimeImmutable($year.'-12-31');
            $amount = $this->amountForPeriod($asset, $from, $to);

            if ($amount > 0) {
                $plan[] = ['from' => $from, 'to' => $to, 'amount' => $amount, 'accumulated' => $this->accumulatedAt($asset, $to)];
            }
        }

        return $plan;
    }

    /**
     * Books the fiscal year's depreciation of every asset of its ledger.
     *
     * @return list<JournalEntry>
     */
    public function bookFiscalYear(FiscalYear $fiscalYear): array
    {
        $entries = [];

        foreach ($this->assetRepository->findActiveDuring($fiscalYear->getLedger(), $fiscalYear->getDateStart(), $fiscalYear->getDateEnd()) as $asset) {
            if ($entry = $this->bookAsset($asset, $fiscalYear)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function bookAsset(
        FixedAsset $asset,
        FiscalYear $fiscalYear
    ): ?JournalEntry {
        $sourceId = $asset->getId().'@'.$fiscalYear->getId();

        if ([] !== $this->entryRepository->findBySource($asset->getLedger(), self::SOURCE, $sourceId)) {
            return null;
        }

        $amount = $this->amountForPeriod($asset, $fiscalYear->getDateStart(), $fiscalYear->getDateEnd());

        // Depreciation booked before the books were taken over is not booked again.
        $alreadyBefore = $asset->getPriorDepreciation() - $this->accumulatedAt($asset, $fiscalYear->getDateStart()->modify('-1 day'));
        $amount -= max(0, min($amount, $alreadyBefore));

        if ($amount <= 0) {
            return null;
        }

        $date = min($fiscalYear->getDateEnd(), $asset->getDateDisposed() ?? $fiscalYear->getDateEnd());

        return $this->postingService->postDraft(
            $asset->getLedger(),
            (new EntryDraft(JournalType::Miscellaneous, $date, 'Depreciation '.$asset->getLabel()))
                ->source(self::SOURCE, $sourceId)
                ->debit($this->expenseAccount($asset), $amount)
                ->credit($this->depreciationAccount($asset), $amount)
        );
    }

    /**
     * Takes an asset out of the books (sold or scrapped): its depreciation up to
     * that day is booked, then the asset leaves its account, the accumulated
     * depreciation is cancelled, and the remaining book value becomes a charge.
     * A sale's price is invoiced apart.
     */
    public function dispose(
        FixedAsset $asset,
        DateTimeImmutable $date,
        FiscalYear $fiscalYear
    ): JournalEntry {
        if ($asset->getDateDisposed()) {
            throw new AccountingException('This asset is already disposed of.');
        }

        $asset->setDateDisposed($date);
        $this->bookAsset($asset, $fiscalYear);

        $accumulated = max($this->accumulatedAt($asset, $date), $asset->getPriorDepreciation());
        $bookValue = $asset->getCost() - $accumulated;

        $draft = (new EntryDraft(JournalType::Miscellaneous, $date, 'Disposal '.$asset->getLabel()))
            ->source(self::SOURCE_DISPOSAL, (string) $asset->getId())
            ->debit($this->depreciationAccount($asset), $accumulated)
            ->debit(AccountRole::AssetDisposalValue, $bookValue)
            ->credit($asset->getAccountNumber(), $asset->getCost());

        $entry = $this->postingService->postDraft($asset->getLedger(), $draft);
        $this->entityManager->flush();

        return $entry;
    }

    public function depreciationAccount(FixedAsset $asset): string
    {
        return $asset->getDepreciationAccountNumber()
            ?? $this->jurisdictionRegistry->forLedger($asset->getLedger())->getDepreciationAccountNumber($asset->getAccountNumber());
    }

    public function expenseAccount(FixedAsset $asset): string
    {
        return $asset->getExpenseAccountNumber()
            ?? $this->chartService->getRoleNumber($asset->getLedger(), AccountRole::DepreciationExpense);
    }
}
