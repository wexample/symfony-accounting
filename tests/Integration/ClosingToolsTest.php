<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Helper\IbanHelper;
use Wexample\SymfonyAccounting\Service\Asset\DepreciationService;
use Wexample\SymfonyAccounting\Service\Bank\SepaCreditTransferBuilder;
use Wexample\SymfonyAccounting\Service\Invoice\DunningService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Ledger\AccrualService;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearService;
use Wexample\SymfonyCheck\Service\CheckService;

class ClosingToolsTest extends AbstractAccountingTestCase
{
    public function testDepreciation(): void
    {
        $ledger = $this->createLedger();
        $fiscalYears = $this->service(FiscalYearService::class);
        $fiscalYears->createNext($ledger);
        $supplier = $this->createParty($ledger, 'Computer shop', customer: false, supplier: true);
        $factory = $this->service(InvoiceFactory::class);

        // A computer bought on 1 July, 1 200.00, three years.
        $purchase = $factory->create($ledger, direction: InvoiceDirection::Purchase, party: $supplier, dateInvoice: new DateTimeImmutable('2026-07-01'))->setNumber('PC-1');
        $item = $factory->addItem($purchase, 'Laptop', 120000, goods: true, accountNumber: '2183');
        $this->service(InvoiceEmissionService::class)->emit($purchase);

        $depreciation = $this->service(DepreciationService::class);
        $asset = $depreciation->createFromInvoiceItem($item, 36);

        $plan = $depreciation->schedule($asset);
        $this->assertSame([2026, 2027, 2028, 2029], array_map(fn ($row) => (int) $row['from']->format('Y'), $plan));
        $this->assertSame(120000, array_sum(array_column($plan, 'amount')));
        // 184 of 1 096 days in 2026.
        $this->assertSame(20146, $plan[0]['amount']);

        $year = $fiscalYears->getForDate($ledger, new DateTimeImmutable('2026-12-31'));
        $this->assertTrue($this->service(CheckService::class)->check($year)->has('fiscal_year.depreciation_not_booked'));

        $entries = $depreciation->bookFiscalYear($year);
        $this->assertSame(['681:20146/0', '28183:0/20146'], $this->describeLines($entries[0]));
        $this->assertSame([], $depreciation->bookFiscalYear($year));
        $this->assertFalse($this->service(CheckService::class)->check($year)->has('fiscal_year.depreciation_not_booked'));

        // Sold on 30 June 2027.
        $next = $fiscalYears->getForDate($ledger, new DateTimeImmutable('2027-06-30'));
        $disposal = $depreciation->dispose($asset, new DateTimeImmutable('2027-06-30'), $next);
        $accumulated = $depreciation->accumulatedAt($asset, new DateTimeImmutable('2027-06-30'));
        // 365 of 1 096 days.
        $this->assertSame(39964, $accumulated);
        $this->assertSame(
            ['28183:'.$accumulated.'/0', '675:'.(120000 - $accumulated).'/0', '2183:0/120000'],
            $this->describeLines($disposal)
        );
    }

    public function testAccruals(): void
    {
        $ledger = $this->createLedger();
        $this->service(FiscalYearService::class)->createNext($ledger);

        [$entry, $reversal] = $this->service(AccrualService::class)->prepaidExpense($ledger, '613', 30000, new DateTimeImmutable('2026-12-31'), 'Insurance 2027');

        $this->assertSame(['486:30000/0', '613:0/30000'], $this->describeLines($entry));
        $this->assertSame('2027-01-01', $reversal->getDate()->format('Y-m-d'));
        $this->assertSame(['486:0/30000', '613:30000/0'], $this->describeLines($reversal));
    }

    public function testDunning(): void
    {
        $ledger = $this->createLedger();
        $party = $this->createParty($ledger);
        $late = $this->emitSale($ledger, $party, 10000, '2026-01-01');      // due 31 Jan
        $this->emitSale($ledger, $party, 10000, '2026-03-01');              // due 31 Mar
        $dunning = $this->service(DunningService::class);

        $notices = $dunning->findDue($ledger, new DateTimeImmutable('2026-03-05'));
        $this->assertCount(1, $notices);
        $this->assertSame($late, $notices[0]->invoice);
        $this->assertSame(2, $notices[0]->level);
        $this->assertSame(33, $notices[0]->daysLate);

        $dunning->record($notices[0], new DateTimeImmutable('2026-03-05'));
        $this->assertSame([], $dunning->findDue($ledger, new DateTimeImmutable('2026-03-10')));
        $this->assertSame(3, $dunning->findDue($ledger, new DateTimeImmutable('2026-04-05'))[0]->level);
        $this->assertSame('2026-03-05', $late->getMetadata()['reminders'][0]['date']);
    }

    public function testSepaCreditTransfer(): void
    {
        $this->assertTrue(IbanHelper::isValid('BE68 5390 0754 7034'));
        $this->assertFalse(IbanHelper::isValid('BE68 5390 0754 7035'));
        $this->assertTrue(IbanHelper::isValid('FR76 3000 1007 9412 3456 7890 185'));

        $ledger = $this->createLedger();
        $bank = $this->createBankAccount($ledger)->setIban('BE68539007547034')->setBic('GKCCBEBB')->setHolder('Test Company');
        $supplier = $this->createParty($ledger, 'Fournisseur Été', customer: false, supplier: true)->setIban('FR7630001007941234567890185');
        $factory = $this->service(InvoiceFactory::class);
        $purchase = $factory->create($ledger, direction: InvoiceDirection::Purchase, party: $supplier)->setNumber('F-2026-12')->setPaymentReference('+++090/9337/55493+++');
        $factory->addItem($purchase, 'Hosting', 10000);
        $this->service(InvoiceEmissionService::class)->emit($purchase);

        $builder = $this->service(SepaCreditTransferBuilder::class);
        $payments = $builder->paymentsForPurchases([$purchase]);
        $xml = $builder->build($bank, $payments, new DateTimeImmutable('2026-04-01'), 'MSG-1');

        $document = simplexml_load_string($xml);
        $document->registerXPathNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03');
        $this->assertSame('120.00', (string) $document->xpath('//p:GrpHdr/p:CtrlSum')[0]);
        $this->assertSame('FR7630001007941234567890185', (string) $document->xpath('//p:CdtrAcct//p:IBAN')[0]);
        $this->assertSame('Fournisseur Ete', (string) $document->xpath('//p:Cdtr/p:Nm')[0]);
        $this->assertSame('090933755493', (string) $document->xpath('//p:CdtrRefInf/p:Ref')[0]);
        $this->assertSame('BBA', (string) $document->xpath('//p:CdtrRefInf/p:Tp/p:Issr')[0]);
        $this->assertSame('2026-04-01', (string) $document->xpath('//p:ReqdExctnDt')[0]);
    }
}
