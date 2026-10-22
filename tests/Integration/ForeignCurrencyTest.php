<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyAccounting\Service\Bank\AllocationService;
use Wexample\SymfonyAccounting\Service\Document\InvoiceDocumentDataBuilder;
use Wexample\SymfonyAccounting\Service\Document\UblInvoiceBuilder;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Ledger\AccrualService;
use Wexample\SymfonyAccounting\Service\Ledger\ExchangeRevaluationService;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearService;
use Wexample\SymfonyMoney\Class\ExchangeRate;
use Wexample\SymfonyMoney\Interface\ExchangeRateProviderInterface;
use Wexample\SymfonyMoney\Service\ExchangeRateService;

class ForeignCurrencyTest extends AbstractAccountingTestCase
{
    private function sellInDollars(
        Ledger $ledger,
        Party $party,
        ?string $rate,
        string $date = '2026-03-02'
    ): Invoice {
        $factory = $this->service(InvoiceFactory::class);
        $invoice = $factory->create($ledger, party: $party, dateInvoice: new DateTimeImmutable($date))
            ->setCurrencyCode('USD')
            ->setExchangeRate($rate);
        $factory->addItem($invoice, 'Consulting', 100000);
        $this->em()->flush();

        return $this->service(InvoiceEmissionService::class)->emit($invoice);
    }

    /**
     * @return array<string, array{debit: int, credit: int, currency: ?int}>
     */
    private function lines(Invoice $invoice): array
    {
        $lines = [];

        foreach ($this->entryOf($invoice)->getLines() as $line) {
            $lines[$line->getAccount()->getNumber()] = ['debit' => $line->getDebit(), 'credit' => $line->getCredit(), 'currency' => $line->getCurrencyAmount()];
        }

        return $lines;
    }

    public function testWithoutRateNorProviderTheDocumentWaits(): void
    {
        $ledger = $this->createLedger();

        $this->expectException(AccountingException::class);
        $this->expectExceptionMessage('No exchange rate from USD to EUR');

        $this->sellInDollars($ledger, $this->createParty($ledger, 'US Inc', 'US'), null);
    }

    public function testTheBooksKeepTheLedgersCurrencyAndTheDocumentsBeside(): void
    {
        $ledger = $this->createLedger('FR');
        $sale = $this->sellInDollars($ledger, $this->createParty($ledger, 'Client SA'), '0.92');
        $lines = $this->lines($sale);

        // 1 000 USD + 20 % VAT = 1 200 USD, at 0.92.
        $this->assertSame(['debit' => 110400, 'credit' => 0, 'currency' => 120000], $lines['411']);
        $this->assertSame(18400, array_sum(array_column(array_filter($lines, fn ($line, $number) => str_starts_with((string) $number, '445'), ARRAY_FILTER_USE_BOTH), 'credit')));

        $printed = $this->service(InvoiceDocumentDataBuilder::class)->build($sale, 'en')['totals']['vatInLedgerCurrency'];
        $this->assertSame(['amount' => 18400, 'rate' => '0.92'], ['amount' => $printed['amount'], 'rate' => $printed['rate']]);

        $ubl = $this->service(UblInvoiceBuilder::class)->build($sale);
        $this->assertStringContainsString('<cbc:TaxCurrencyCode>EUR</cbc:TaxCurrencyCode>', $ubl);
        $this->assertStringContainsString('<cbc:TaxAmount currencyID="EUR">184.00</cbc:TaxAmount>', $ubl);
    }

    public function testThePaymentBooksTheExchangeDifference(): void
    {
        $ledger = $this->createLedger('FR');
        $sale = $this->sellInDollars($ledger, $this->createParty($ledger, 'Client SA'), '0.92');
        $line = $this->createTransaction($this->createBankAccount($ledger), 112000, '2026-03-20');

        $allocation = $this->service(AllocationService::class)->allocate($line, $sale);
        $this->em()->flush();

        $this->assertSame(120000, $allocation->getInvoiceAmount());
        $this->assertTrue($sale->isFullyPaid());

        $entry = $allocation->getEntry();
        $byAccount = [];
        foreach ($entry->getLines() as $entryLine) {
            $byAccount[$entryLine->getAccount()->getNumber()] = $entryLine->getCredit() - $entryLine->getDebit();
        }

        // Booked at 1 104, paid 1 120: a gain of 16.
        $this->assertSame(110400, $byAccount['411']);
        $this->assertSame(1600, $byAccount['766']);
    }

    public function testWhatIsStillOwedIsRevaluedAtClosingAndReversedTheNextDay(): void
    {
        $ledger = $this->createLedger('FR');
        $this->sellInDollars($ledger, $this->createParty($ledger, 'Client SA'), '0.92', '2026-11-02');
        $this->service(FiscalYearService::class)->createNext($ledger);

        $provider = new class () implements ExchangeRateProviderInterface {
            public function getRate(string $from, string $to, DateTimeImmutable $date): ?ExchangeRate
            {
                return new ExchangeRate($from, $to, '0.95', $date, 'test');
            }
        };
        $revaluation = new ExchangeRevaluationService(
            $this->service(InvoiceRepository::class),
            new ExchangeRateService($provider),
            $this->service(AccrualService::class),
            $this->service(JournalEntryRepository::class),
        );

        [$entry, $reversal] = $revaluation->revalue($ledger, new DateTimeImmutable('2026-12-31'));

        // 1 200 USD worth 1 140 now against 1 104 booked: 36 unrealized (the French chart keeps it on 477).
        $amounts = [];
        foreach ($entry->getLines() as $entryLine) {
            $amounts[$entryLine->getAccount()->getNumber()] = $entryLine->getDebit() - $entryLine->getCredit();
        }
        $this->assertSame(['411' => 3600, '766' => -3600], $amounts);
        $this->assertSame('2027-01-01', $reversal->getDate()->format('Y-m-d'));

        $this->expectException(AccountingException::class);
        $revaluation->revalue($ledger, new DateTimeImmutable('2026-12-31'));
    }
}
