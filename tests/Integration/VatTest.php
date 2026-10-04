<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\VatKind;
use Wexample\SymfonyAccounting\Enum\VatPeriodicity;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Service\Bank\AllocationService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Vat\VatPeriodService;
use Wexample\SymfonyAccounting\Service\Vat\VatReturnService;

class VatTest extends AbstractAccountingTestCase
{
    private function vat(): VatReturnService
    {
        return $this->service(VatReturnService::class);
    }

    private function purchase($ledger, $supplier, int $price, string $date, int $rate = 2000): void
    {
        $factory = $this->service(InvoiceFactory::class);
        $purchase = $factory->create($ledger, direction: InvoiceDirection::Purchase, party: $supplier, dateInvoice: new DateTimeImmutable($date))
            ->setNumber('P-'.uniqid());
        $factory->addItem($purchase, 'Purchase', $price, vatRate: $rate);
        $this->service(InvoiceEmissionService::class)->emit($purchase);
    }

    public function testPeriods(): void
    {
        $ledger = $this->createLedger();
        $ledger->setVatPeriodicity(VatPeriodicity::Quarterly);
        $periods = $this->service(VatPeriodService::class);

        [$from, $to] = $periods->getPeriodFor($ledger, new DateTimeImmutable('2026-05-17'));
        $this->assertSame(['2026-04-01', '2026-06-30'], [$from->format('Y-m-d'), $to->format('Y-m-d')]);
        $this->assertCount(4, $periods->getPeriodsOfYear($ledger, 2026));

        $ledger->setVatPeriodicity(VatPeriodicity::Monthly);
        $this->assertCount(12, $periods->getPeriodsOfYear($ledger, 2026));
    }

    public function testQuarterlyReturnAndSettlement(): void
    {
        $ledger = $this->createLedger('BE');
        $customer = $this->createParty($ledger);
        $supplier = $this->createParty($ledger, 'Supplier', customer: false, supplier: true);
        $foreignCustomer = $this->createParty($ledger, 'Dutch BV', 'NL', 'NL123456789B01');
        $foreignSupplier = $this->createParty($ledger, 'US Inc', 'US', customer: false, supplier: true);

        $this->emitSale($ledger, $customer, 100000, '2026-01-15');
        $this->emitSale($ledger, $foreignCustomer, 50000, '2026-02-15');
        $this->purchase($ledger, $supplier, 30000, '2026-02-01');
        $this->purchase($ledger, $foreignSupplier, 10000, '2026-03-01');
        // Outside the quarter.
        $this->emitSale($ledger, $customer, 999999, '2026-04-02');

        $return = $this->vat()->compute($ledger, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-03-31'));

        $domesticSales = $return->sum(InvoiceDirection::Sale, VatKind::Domestic);
        $this->assertSame(['base' => 100000, 'due' => 20000, 'deductible' => 0], array_slice($domesticSales, 0, 3));
        $this->assertSame(50000, $return->sum(InvoiceDirection::Sale, VatKind::IntraEuServices)['base']);
        $reverseCharge = $return->sum(InvoiceDirection::Purchase, VatKind::ReverseCharge);
        $this->assertSame(['base' => 10000, 'due' => 2000, 'deductible' => 2000], array_slice($reverseCharge, 0, 3));
        $this->assertSame(22000, $return->getDue());
        $this->assertSame(8000, $return->getDeductible());
        $this->assertSame(14000, $return->getNet());

        $entry = $this->vat()->settle($return);
        $this->assertTrue($entry->isBalanced());
        $this->assertContains('4455:0/14000', $this->describeLines($entry));

        $this->expectException(AccountingException::class);
        $this->vat()->settle($return);
    }

    public function testCreditIsCarriedForward(): void
    {
        $ledger = $this->createLedger();
        $supplier = $this->createParty($ledger, 'Supplier', customer: false, supplier: true);
        $this->purchase($ledger, $supplier, 50000, '2026-01-10');

        $first = $this->vat()->compute($ledger, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-03-31'));
        $this->assertSame(-10000, $first->getNet());
        $this->assertContains('4453:10000/0', $this->describeLines($this->vat()->settle($first)));

        $this->emitSale($ledger, $this->createParty($ledger), 80000, '2026-04-10');
        $second = $this->vat()->compute($ledger, new DateTimeImmutable('2026-04-01'), new DateTimeImmutable('2026-06-30'));
        $this->assertSame(10000, $second->previousCredit);
        $this->assertSame(16000 - 10000, $second->getNet());
    }

    public function testVatOnPaymentsIsDueWhenPaid(): void
    {
        $ledger = $this->createLedger();
        $ledger->setVatOnPayments(true);
        $bank = $this->createBankAccount($ledger);
        $invoice = $this->emitSale($ledger, $this->createParty($ledger), 100000, '2026-03-20');

        $q1 = $this->vat()->compute($ledger, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-03-31'));
        $this->assertSame(0, $q1->getDue());

        // Half paid in April.
        $this->service(AllocationService::class)->allocate($this->createTransaction($bank, 60000, '2026-04-05'), $invoice);
        $q2 = $this->vat()->compute($ledger, new DateTimeImmutable('2026-04-01'), new DateTimeImmutable('2026-06-30'));
        $this->assertSame(10000, $q2->getDue());
        $this->assertSame(50000, $q2->sum(InvoiceDirection::Sale)['base']);
    }

    public function testDeposits(): void
    {
        $ledger = $this->createLedger();
        $ledger->setVatPeriodicity(VatPeriodicity::Annual);
        $bank = $this->createBankAccount($ledger);
        $this->emitSale($ledger, $this->createParty($ledger), 100000, '2026-03-01');
        $this->service(AllocationService::class)->allocateToAccount($this->createTransaction($bank, -5500, '2026-07-15'), '4451');

        $year = $this->vat()->compute($ledger, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'));
        $this->assertSame(5500, $year->deposits);
        $this->assertSame(20000 - 5500, $year->getNet());
    }
}
