<?php

namespace Wexample\SymfonyAccounting\Checker;

use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Repository\FixedAssetRepository;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyAccounting\Service\Asset\DepreciationService;
use Wexample\SymfonyCheck\Class\Finding;
use Wexample\SymfonyCheck\Interface\CheckerInterface;

/**
 * Before closing: every asset in service has its depreciation of the year booked.
 */
class DepreciationChecker implements CheckerInterface
{
    public function __construct(
        private readonly FixedAssetRepository $assetRepository,
        private readonly JournalEntryRepository $entryRepository,
        private readonly DepreciationService $depreciationService,
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
        $missing = 0;

        foreach ($this->assetRepository->findActiveDuring($subject->getLedger(), $subject->getDateStart(), $subject->getDateEnd()) as $asset) {
            $due = $this->depreciationService->amountForPeriod($asset, $subject->getDateStart(), $subject->getDateEnd());
            $booked = $this->entryRepository->findBySource($subject->getLedger(), DepreciationService::SOURCE, $asset->getId().'@'.$subject->getId());

            if ($due > 0 && [] === $booked) {
                ++$missing;
            }
        }

        if ($missing > 0) {
            yield Finding::warning('fiscal_year.depreciation_not_booked', ['count' => $missing], FiscalYearChecker::DOMAIN);
        }
    }
}
