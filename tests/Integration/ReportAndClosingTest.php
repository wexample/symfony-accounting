<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Class\ReportDefinition;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Exception\ClosedFiscalYearException;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyAccounting\Service\Bank\AllocationService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearClosingService;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearService;
use Wexample\SymfonyAccounting\Service\Ledger\PostingService;
use Wexample\SymfonyAccounting\Service\Report\AgedBalanceService;
use Wexample\SymfonyAccounting\Service\Report\FinancialStatementService;
use Wexample\SymfonyAccounting\Service\Report\GeneralLedgerService;
use Wexample\SymfonyAccounting\Service\Report\TrialBalanceService;

class ReportAndClosingTest extends AbstractAccountingTestCase
{
    /**
     * A small year: a sale paid, a purchase paid, an unpaid sale, a bank charge.
     */
    private function buildYear(): array
    {
        $ledger = $this->createLedger();
        $bank = $this->createBankAccount($ledger);
        $customer = $this->createParty($ledger);
        $supplier = $this->createParty($ledger, 'Supplier', customer: false, supplier: true);
        $allocations = $this->service(AllocationService::class);
        $factory = $this->service(InvoiceFactory::class);

        $sale = $this->emitSale($ledger, $customer, 100000, '2026-02-01');
        $allocations->allocate($this->createTransaction($bank, 120000, '2026-02-20'), $sale);

        $purchase = $factory->create($ledger, direction: InvoiceDirection::Purchase, party: $supplier, dateInvoice: new DateTimeImmutable('2026-03-01'))->setNumber('P-1');
        $factory->addItem($purchase, 'Hosting', 30000);
        $this->service(InvoiceEmissionService::class)->emit($purchase);
        $allocations->allocate($this->createTransaction($bank, -36000, '2026-03-10'), $purchase);

        $this->emitSale($ledger, $customer, 20000, '2026-11-15');
        $allocations->allocateToAccount($this->createTransaction($bank, -1500, '2026-12-01'), '627');

        $fiscalYear = $this->service(FiscalYearService::class)->getForDate($ledger, new DateTimeImmutable('2026-06-01'));

        return [$ledger, $fiscalYear, $bank];
    }

    public function testTrialBalance(): void
    {
        [$ledger, $fiscalYear] = $this->buildYear();
        $balance = $this->service(TrialBalanceService::class)->build($ledger, $fiscalYear);

        $this->assertTrue($balance->isBalanced());
        $this->assertSame(-120000, $balance->getBalance('706'));
        $this->assertSame(24000, $balance->getBalance('411'));
        $this->assertSame(82500, $balance->getBalance('5121'));
        $this->assertSame(-24000 + 6000, $balance->getBalance('445'));
        $this->assertArrayHasKey(6, $balance->getClassTotals());
    }

    public function testFinancialStatements(): void
    {
        [, $fiscalYear] = $this->buildYear();
        $statements = $this->service(FinancialStatementService::class);

        $income = $statements->build($fiscalYear, 'income_statement');
        $this->assertSame(120000, $income->get('income'));
        $this->assertSame(31500, $income->get('charges'));
        $this->assertSame(88500, $income->get('result'));

        $sheet = $statements->build($fiscalYear, 'balance_sheet');
        $this->assertSame($sheet->get('assets'), $sheet->get('liabilities'));
        $this->assertSame(88500, $sheet->get('year_result'));
        // 411 (24000) + VAT credit side… : receivables are the debit-side class 4 accounts.
        $this->assertSame(24000 + 6000, $sheet->get('receivables'));
        $this->assertSame(82500, $sheet->get('cash'));
    }

    public function testFormulasAndOverlaps(): void
    {
        $statements = $this->service(FinancialStatementService::class);
        $definition = ReportDefinition::fromArray([
            'key' => 'test',
            'lines' => [
                ['key' => 'I', 'accounts' => ['70', '75!755'], 'sign' => -1],
                ['key' => 'II', 'accounts' => ['60', '65!655']],
                ['key' => 'III', 'accounts' => ['755'], 'sign' => -1],
                ['key' => 'VII', 'accounts' => ['77'], 'sign' => -1],
                ['key' => 'VIII', 'accounts' => ['67']],
                ['key' => 'exceptional', 'formula' => 'VII-VIII'],
                ['key' => 'result', 'formula' => 'I-II+III+exceptional'],
            ],
        ]);

        $trial = new \Wexample\SymfonyAccounting\Class\TrialBalance([
            '706' => new \Wexample\SymfonyAccounting\Class\TrialBalanceRow('706', '', 0, 1000),
            '755' => new \Wexample\SymfonyAccounting\Class\TrialBalanceRow('755', '', 0, 50),
            '606' => new \Wexample\SymfonyAccounting\Class\TrialBalanceRow('606', '', 300, 0),
            '771' => new \Wexample\SymfonyAccounting\Class\TrialBalanceRow('771', '', 0, 200),
            '671' => new \Wexample\SymfonyAccounting\Class\TrialBalanceRow('671', '', 80, 0),
        ]);

        $result = $statements->compute($definition, $trial);
        $this->assertSame(1000, $result->get('I'));
        $this->assertSame(50, $result->get('III'));
        $this->assertSame(120, $result->get('exceptional'));
        $this->assertSame(1000 - 300 + 50 + 120, $result->get('result'));

        $overlapping = ReportDefinition::fromArray(['key' => 'bad', 'lines' => [
            ['key' => 'a', 'accounts' => ['15']],
            ['key' => 'b', 'accounts' => ['151']],
        ]]);
        $this->assertSame(['1511' => ['a', 'b']], $statements->findOverlaps($overlapping, ['1511', '160']));
    }

    public function testGeneralLedgerAndAgedBalance(): void
    {
        [$ledger, $fiscalYear] = $this->buildYear();

        $generalLedger = $this->service(GeneralLedgerService::class)->build($ledger, $fiscalYear, byAuxiliary: true, accountPrefix: '411');
        $customer = $generalLedger['411/CCLIENTA'];
        $this->assertSame([120000, 0, 24000], array_column($customer['lines'], 'balance'));
        $this->assertSame(24000, $customer['balance']);

        $aged = $this->service(AgedBalanceService::class)->build($ledger, AccountRole::Customers, new DateTimeImmutable('2027-02-01'));
        $this->assertSame(24000, $aged['CCLIENTA']['total']);
        $this->assertSame(24000, $aged['CCLIENTA']['buckets']['31_60']);
    }

    public function testClosing(): void
    {
        [$ledger, $fiscalYear, $bank] = $this->buildYear();
        $closing = $this->service(FiscalYearClosingService::class);

        $closed = $closing->close($fiscalYear);
        $this->assertTrue($closed->isClosed());
        $this->assertSame(88500, $closed->getResult());

        $next = $this->service(FiscalYearRepository::class)->findNext($fiscalYear);
        $opening = $this->service(JournalEntryRepository::class)->findBySource($ledger, FiscalYearClosingService::SOURCE_OPENING, (string) $fiscalYear->getId());
        $this->assertCount(1, $opening);
        $this->assertSame($next, $opening[0]->getFiscalYear());
        $this->assertSame('AN', $opening[0]->getJournal()->getCode());
        $this->assertTrue($opening[0]->isBalanced());
        $lines = $this->describeLines($opening[0]);
        $this->assertContains('120:0/88500', $lines);
        $this->assertContains('5121:82500/0', $lines);
        $this->assertContains('411:24000/0', $lines);
        $this->assertSame($opening[0]->getLines()[array_search('411:24000/0', $lines)]->getParty()?->getName(), 'Client A');

        // Twice: nothing more.
        $closing->close($fiscalYear);
        $this->assertCount(1, $this->service(JournalEntryRepository::class)->findBySource($ledger, FiscalYearClosingService::SOURCE_OPENING, (string) $fiscalYear->getId()));

        // Appropriation into retained earnings.
        $appropriation = $closing->appropriateResult($fiscalYear);
        $this->assertSame(['120:88500/0', '110:0/88500'], $this->describeLines($appropriation));

        $this->expectException(ClosedFiscalYearException::class);
        $this->service(PostingService::class)->postDraft($ledger, (new EntryDraft(JournalType::Miscellaneous, new DateTimeImmutable('2026-12-31'), 'Late'))
            ->debit('627', 100)->credit('5121', 100));
    }

    public function testClosingIsBlockedByErrors(): void
    {
        $ledger = $this->createLedger();
        $fiscalYear = $this->service(FiscalYearService::class)->getForDate($ledger, new DateTimeImmutable('2026-01-01'));
        $draft = $this->service(PostingService::class)->build($ledger, (new EntryDraft(JournalType::Miscellaneous, new DateTimeImmutable('2026-05-01'), 'Draft'))
            ->debit('627', 100)->credit('5121', 100));
        $this->em()->persist($draft);
        $this->em()->flush();

        try {
            $this->service(FiscalYearClosingService::class)->close($fiscalYear);
            $this->fail('A draft entry blocks the closing.');
        } catch (AccountingException $exception) {
            $this->assertStringContainsString('fiscal_year.draft_entries', $exception->getMessage());
        }

        $this->assertTrue($this->service(FiscalYearClosingService::class)->close($fiscalYear, force: true)->isClosed());
    }

    public function testLossAndReopening(): void
    {
        $ledger = $this->createLedger();
        $bank = $this->createBankAccount($ledger);
        $this->service(AllocationService::class)->allocateToAccount($this->createTransaction($bank, -5000, '2026-04-01'), '627');
        $fiscalYear = $this->service(FiscalYearService::class)->getForDate($ledger, new DateTimeImmutable('2026-04-01'));
        $closing = $this->service(FiscalYearClosingService::class);

        $closing->close($fiscalYear);
        $this->assertSame(-5000, $fiscalYear->getResult());

        $closing->reopen($fiscalYear);
        $this->assertFalse($fiscalYear->isClosed());
        $this->assertNull($fiscalYear->getResult());

        $closing->close($fiscalYear);
        $this->assertSame(-5000, $fiscalYear->getResult());

        // Opening, its reversal, the new opening.
        $openings = $this->service(JournalEntryRepository::class)->findBySource($ledger, FiscalYearClosingService::SOURCE_OPENING, (string) $fiscalYear->getId());
        $this->assertCount(3, $openings);
        $this->assertContains('129:5000/0', $this->describeLines($openings[2]));
    }
}
