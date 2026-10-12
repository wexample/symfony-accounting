<?php

namespace Wexample\SymfonyAccounting\Service\Vat;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Enum\VatKind;
use Wexample\SymfonyAccounting\Enum\VatRole;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Helper\EuHelper;
use Wexample\SymfonyAccounting\Repository\EntryLineRepository;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyAccounting\Service\Ledger\PostingService;
use Wexample\SymfonyMoney\Helper\MoneyHelper;

/**
 * The One-Stop Shop: distance sales to consumers in other EU countries, taxed at
 * the customer's country's rate once the yearly EU-wide threshold is passed (this
 * year or the last), or from the start when the ledger registered for it
 * (setting `oss_registered`). Goods count; services count only for a ledger
 * selling services delivered electronically (setting `oss_electronic_services`):
 * other services to consumers stay taxed where the seller is.
 *
 * Its VAT is kept on its own account and declared each quarter by country and
 * rate, apart from the national return.
 */
class OssService
{
    /** 10 000 €, in cents. */
    public const int THRESHOLD = 1_000_000;

    public const string SOURCE_SETTLEMENT = 'oss_settlement';

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly EntryLineRepository $lineRepository,
        private readonly JournalEntryRepository $entryRepository,
        private readonly JurisdictionRegistry $jurisdictionRegistry,
        private readonly PostingService $postingService,
    ) {
    }

    public function isEligible(
        Ledger $ledger,
        InvoiceItem $item
    ): bool {
        return $item->isGoods() || $ledger->getSetting('oss_electronic_services', false);
    }

    /**
     * Whether the document's distance sales are taxed in the customer's country:
     * the sale that passes the threshold is the first one taxed there.
     */
    public function applies(Invoice $invoice): bool
    {
        $ledger = $invoice->getLedger();

        if (! $invoice->isSale() || ! $ledger->isVatSubject() || ! $this->isDistanceCustomer($ledger, $invoice->getParty())) {
            return false;
        }

        if ($ledger->getSetting('oss_registered', false)) {
            return true;
        }

        $year = (int) $invoice->getDateInvoice()->format('Y');

        return $this->getDistanceSales($ledger, $year - 1) > self::THRESHOLD
            || $this->getDistanceSales($ledger, $year, $invoice) + $this->eligibleBase($invoice) > self::THRESHOLD;
    }

    /**
     * The distance sales of a calendar year that count toward the threshold, net
     * of credit notes, in cents.
     */
    public function getDistanceSales(
        Ledger $ledger,
        int $year,
        ?Invoice $except = null
    ): int {
        $total = 0;

        foreach ($this->invoiceRepository->findForPeriod($ledger, new DateTimeImmutable($year.'-01-01'), new DateTimeImmutable($year.'-12-31')) as $invoice) {
            if ($invoice === $except
                || ! $invoice->isSale()
                || ! $invoice->getStatus()->isEmitted()
                || ! in_array($invoice->getType(), [InvoiceType::Bill, InvoiceType::Penalty, InvoiceType::CreditNote], true)
                || ! $this->isDistanceCustomer($ledger, $invoice->getParty())) {
                continue;
            }

            $total += ($invoice->isCreditNote() ? -1 : 1) * $this->eligibleBase($invoice);
        }

        return $total;
    }

    /**
     * The OSS return of a period: base and VAT by country and rate.
     *
     * @return list<array{country: string, rate: int, base: int, vat: int}>
     */
    public function computeReturn(
        Ledger $ledger,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array {
        $jurisdiction = $this->jurisdictionRegistry->forLedger($ledger);
        $lines = [];

        foreach ($this->lineRepository->findVatLines($ledger, $from, $to) as $line) {
            $code = $jurisdiction->getVatCode((string) $line->getVatCode());

            if (VatKind::Oss !== $code?->kind) {
                continue;
            }

            $key = $code->country.'_'.$code->rate;
            $lines[$key] ??= ['country' => (string) $code->country, 'rate' => $code->rate, 'base' => 0, 'vat' => 0];
            // Sales are credited: their balance is negative.
            $lines[$key][VatRole::Base === $line->getVatRole() ? 'base' : 'vat'] -= $line->getBalance();
        }

        ksort($lines);

        return array_values($lines);
    }

    /**
     * Moves the period's OSS VAT to what is payable.
     */
    public function settle(
        Ledger $ledger,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?DateTimeImmutable $date = null
    ): JournalEntry {
        $period = $from->format('Y-m-d').'_'.$to->format('Y-m-d');

        if ([] !== $this->entryRepository->findBySource($ledger, self::SOURCE_SETTLEMENT, $period)) {
            throw new AccountingException('This OSS period is already settled.');
        }

        $vat = array_sum(array_column($this->computeReturn($ledger, $from, $to), 'vat'));

        if (0 === $vat) {
            throw new AccountingException('Nothing to settle for this OSS period.');
        }

        $draft = (new EntryDraft(JournalType::Miscellaneous, $date ?? $to, 'OSS return '.$from->format('Y-m-d').' → '.$to->format('Y-m-d')))
            ->source(self::SOURCE_SETTLEMENT, $period)
            ->add(AccountRole::VatCollectedOss, $vat)
            ->add(AccountRole::VatPayable, -$vat);

        return $this->postingService->postDraft($ledger, $draft);
    }

    /**
     * The return as the national OSS portals take it in: one line per country and
     * rate, amounts in the ledger's currency with a decimal point.
     */
    public function exportCsv(
        Ledger $ledger,
        array $lines
    ): string {
        $currency = $ledger->getCurrencyCode();
        $csv = "country;rate;base;vat\n";

        foreach ($lines as $line) {
            $csv .= implode(';', [
                $line['country'],
                number_format($line['rate'] / 100, 2, '.', ''),
                MoneyHelper::toDecimal($line['base'], $currency),
                MoneyHelper::toDecimal($line['vat'], $currency),
            ])."\n";
        }

        return $csv;
    }

    /**
     * A consumer — no VAT number, or a person — in another EU country.
     */
    private function isDistanceCustomer(
        Ledger $ledger,
        ?Party $party
    ): bool {
        $country = $party?->getCountry()?->getIsoAlpha2Code();

        return null !== $country
            && EuHelper::isMemberState($country)
            && $country !== $ledger->getCountry()?->getIsoAlpha2Code()
            && (! $party->hasVatNumber() || $party->isIndividual());
    }

    private function eligibleBase(Invoice $invoice): int
    {
        $base = 0;

        foreach ($invoice->getItems() as $item) {
            if ($this->isEligible($invoice->getLedger(), $item)) {
                $base += $item->calcPriceNet();
            }
        }

        return $base;
    }
}
