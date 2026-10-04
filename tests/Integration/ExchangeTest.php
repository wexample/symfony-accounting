<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Repository\PartyRepository;
use Wexample\SymfonyAccounting\Service\Bank\AllocationService;
use Wexample\SymfonyAccounting\Service\Exchange\EntryImportService;
use Wexample\SymfonyAccounting\Service\Exchange\LedgerExportService;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearService;
use Wexample\SymfonyAccounting\Service\Ledger\LedgerService;
use Wexample\SymfonyAccounting\Service\Ledger\LetteringService;
use Wexample\SymfonyAccounting\Service\Report\TrialBalanceService;

class ExchangeTest extends AbstractAccountingTestCase
{
    public function testCsvRoundTrip(): void
    {
        $ledger = $this->createLedger();
        $bank = $this->createBankAccount($ledger);
        $invoice = $this->emitSale($ledger, $this->createParty($ledger), 10000);
        $this->service(AllocationService::class)->allocate($this->createTransaction($bank, 12000), $invoice);
        $this->service(LetteringService::class)->letterLedger($ledger);
        $fiscalYear = $this->service(FiscalYearService::class)->getForDate($ledger, new DateTimeImmutable('2026-06-01'));

        $file = $this->service(LedgerExportService::class)->export($fiscalYear, 'csv_entries');
        $this->assertSame('entries-2026.csv', $file->filename);
        $this->assertStringContainsString('VE;Sales;1;2026-03-01;411;Customers;CCLIENTA', $file->content);

        // Into another ledger, as an accounting firm taking over the books.
        $target = $this->service(LedgerService::class)->create('Taken over', 'BE', loadChart: false);
        $result = $this->service(EntryImportService::class)->importContent($target, $file->content, options: ['create_fiscal_years' => true]);

        $this->assertTrue($result->isComplete());
        $this->assertSame(2, $result->entries);
        $this->assertSame(1, $result->fiscalYearsCreated);
        $this->assertSame(1, $result->partiesCreated);
        $party = $this->service(PartyRepository::class)->findOneByCustomerCode($target, 'CCLIENTA');
        $this->assertNotNull($party);

        $source = $this->service(TrialBalanceService::class)->build($ledger, $fiscalYear)->getBalances();
        $copy = $this->service(TrialBalanceService::class)->build($target)->getBalances();
        $this->assertSame($source, $copy);
    }

    public function testUnbalancedEntriesAreReported(): void
    {
        $ledger = $this->createLedger();
        $csv = "journal;number;date;account;label;debit;credit\n"
            ."OD;1;2026-02-01;627;Fees;10,00;\n"
            ."OD;1;2026-02-01;512;Fees;;10,00\n"
            ."OD;2;2026-02-02;627;Wrong;5,00;\n"
            ."OD;2;2026-02-02;512;Wrong;;4,00\n";

        $result = $this->service(EntryImportService::class)->importContent($ledger, $csv, 'csv_entries');

        $this->assertSame(1, $result->entries);
        $this->assertArrayHasKey('OD/2', $result->errors);
    }
}
