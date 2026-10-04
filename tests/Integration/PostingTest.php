<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\EntryStatus;
use Wexample\SymfonyAccounting\Enum\FiscalYearStatus;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Exception\ClosedFiscalYearException;
use Wexample\SymfonyAccounting\Exception\NoFiscalYearException;
use Wexample\SymfonyAccounting\Exception\UnbalancedEntryException;
use Wexample\SymfonyAccounting\Repository\AccountRepository;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearService;
use Wexample\SymfonyAccounting\Service\Ledger\PostingService;

class PostingTest extends AbstractAccountingTestCase
{
    private function posting(): PostingService
    {
        return $this->service(PostingService::class);
    }

    private function sale(string $date = '2026-03-10', int $amount = 12000): EntryDraft
    {
        return (new EntryDraft(JournalType::Sales, new DateTimeImmutable($date), 'Sale'))
            ->debit(AccountRole::Customers, $amount)
            ->credit(AccountRole::SalesServices, intdiv($amount * 10, 12))
            ->credit(AccountRole::VatCollected, $amount - intdiv($amount * 10, 12));
    }

    public function testLedgerIsCreatedWithJournalsAndChart(): void
    {
        $ledger = $this->createLedger();
        $accounts = $this->service(AccountRepository::class)->findIndexedByNumber($ledger);

        $this->assertArrayHasKey('411', $accounts);
        $this->assertTrue($accounts['411']->isLettrable());
        $this->assertSame('generic', $accounts['512']->getSource());
    }

    public function testPostingNumbersWithoutGaps(): void
    {
        $ledger = $this->createLedger();

        $first = $this->posting()->postDraft($ledger, $this->sale());
        $second = $this->posting()->postDraft($ledger, $this->sale('2026-03-11'));

        $this->assertSame(1, $first->getNumber());
        $this->assertSame(2, $second->getNumber());
        $this->assertSame(EntryStatus::Posted, $second->getStatus());
        $this->assertCount(3, $first->getLines());
        $this->assertSame(12000, $first->getTotalDebit());
        $this->assertSame('411', $first->getLines()[0]->getAccount()->getNumber());
    }

    public function testUnbalancedEntryIsRefused(): void
    {
        $ledger = $this->createLedger();

        $this->expectException(UnbalancedEntryException::class);
        $this->posting()->postDraft($ledger, (new EntryDraft(JournalType::Miscellaneous, new DateTimeImmutable('2026-02-01'), 'Wrong'))
            ->debit('627', 100)
            ->credit('512', 99));
    }

    public function testOutOfYearAndClosedYear(): void
    {
        $ledger = $this->createLedger();

        try {
            $this->posting()->postDraft($ledger, $this->sale('2027-01-05'));
            $this->fail('No fiscal year covers 2027.');
        } catch (NoFiscalYearException) {
        }

        $fiscalYear = $this->service(FiscalYearService::class)->getForDate($ledger, new DateTimeImmutable('2026-06-01'));
        $fiscalYear->setStatus(FiscalYearStatus::Closed);

        $this->expectException(ClosedFiscalYearException::class);
        $this->posting()->postDraft($ledger, $this->sale());
    }

    public function testReversal(): void
    {
        $ledger = $this->createLedger();
        $entry = $this->posting()->postDraft($ledger, $this->sale());
        $reversal = $this->posting()->reverse($entry, new DateTimeImmutable('2026-04-01'));

        $this->assertSame(2, $reversal->getNumber());
        $this->assertSame(12000, $reversal->getLines()[0]->getCredit());
        $this->assertTrue($reversal->isBalanced());

        $this->expectException(AccountingException::class);
        $this->posting()->delete($entry);
    }

    public function testNonCalendarFiscalYear(): void
    {
        $ledger = $this->createLedger(fiscalYearStart: '2025-07-01');
        $this->service(FiscalYearService::class)->createNext($ledger);

        $june = $this->posting()->postDraft($ledger, $this->sale('2026-06-30'));
        $july = $this->posting()->postDraft($ledger, $this->sale('2026-07-01'));

        $this->assertSame('2025-2026', $june->getFiscalYear()->getLabel());
        $this->assertSame('2026-2027', $july->getFiscalYear()->getLabel());
        // Numbering restarts with each year.
        $this->assertSame(1, $july->getNumber());
    }

    public function testUnknownAccountsAreCreatedFromTheirParent(): void
    {
        $ledger = $this->createLedger();
        $account = $this->service(ChartService::class)->getAccount($ledger, '41100042');

        $this->assertSame('Customers', $account->getLabel());
        $this->assertTrue($account->isLettrable());
        $this->assertSame('custom', $account->getSource());
    }

    public function testOverlappingFiscalYearsAreRefused(): void
    {
        $ledger = $this->createLedger();

        $this->expectException(AccountingException::class);
        $this->service(FiscalYearService::class)->create($ledger, new DateTimeImmutable('2026-06-01'));
    }

    public function testValidation(): void
    {
        $ledger = $this->createLedger();
        $this->posting()->postDraft($ledger, $this->sale('2026-01-10'));
        $late = $this->posting()->postDraft($ledger, $this->sale('2026-02-10'));

        $this->assertSame(1, $this->posting()->validateUntil($ledger, new DateTimeImmutable('2026-01-31')));
        $this->assertSame(EntryStatus::Posted, $late->getStatus());
    }
}
