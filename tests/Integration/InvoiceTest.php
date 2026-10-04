<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\LatePenaltyPolicy;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Enum\VatKind;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Exception\InvoiceTransitionException;
use Wexample\SymfonyAccounting\Repository\InvoiceRelationRepository;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceAccountingService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceNumberingService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceWorkflow;
use Wexample\SymfonyAccounting\Service\Invoice\LatePenaltyCalculator;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyCheck\Service\CheckService;
use Wexample\SymfonyMoney\Enum\PriceUnit;

class InvoiceTest extends AbstractAccountingTestCase
{
    private function factory(): InvoiceFactory
    {
        return $this->service(InvoiceFactory::class);
    }

    private function emission(): InvoiceEmissionService
    {
        return $this->service(InvoiceEmissionService::class);
    }

    public function testPricing(): void
    {
        $ledger = $this->createLedger();
        $invoice = $this->factory()->create($ledger, party: $this->createParty($ledger));
        // 26 days at 125.00.
        $this->factory()->addItem($invoice, 'Development', 12500, 2600);

        $this->assertSame(325000, $invoice->calcPriceSubTotal());
        $this->assertSame(390000, $invoice->calcPriceFinal());

        $invoice->setPriceDiscount(1000, PriceUnit::Percent);
        $this->assertSame(292500, $invoice->calcPriceNet());
        $this->assertSame(351000, $invoice->calcPriceFinal());

        $invoice->setPriceDiscount(null);
        $invoice->setPriceOverridden(11111);
        $this->assertSame(11111, $invoice->calcPriceFinal());
    }

    public function testDurations(): void
    {
        $ledger = $this->createLedger();
        $this->assertSame(21, $this->factory()->durationToQuantity($ledger, '1h30'));
        $this->assertSame(200, $this->factory()->durationToQuantity($ledger, '2j'));
        $this->assertSame(150, $this->factory()->durationToQuantity($ledger, '1d 3h30m'));
    }

    public function testNumbering(): void
    {
        $ledger = $this->createLedger();
        $ledger->setInvoicePrefix('WEE');
        $party = $this->createParty($ledger);
        $numbering = $this->service(InvoiceNumberingService::class);

        $draft = $this->factory()->create($ledger, party: $party, dateInvoice: new DateTimeImmutable('2026-02-01'));
        $this->factory()->addItem($draft, 'A', 1000);
        $this->assertNull($draft->getNumber());
        $this->assertSame('BIL-WEE2026-0001', $numbering->peek($draft));
        $this->assertSame('BIL-WEE2026-0001', $numbering->peek($draft));

        $first = $this->emission()->emit($draft);
        $second = $this->emitSale($ledger, $party);
        $this->assertSame('BIL-WEE2026-0001', $first->getNumber());
        $this->assertSame('BIL-WEE2026-0002', $second->getNumber());

        $quotation = $this->factory()->create($ledger, InvoiceType::Quotation, party: $party);
        $this->factory()->addItem($quotation, 'Q', 1000);
        $this->assertStringStartsWith('QUO-WEE', $this->emission()->emit($quotation)->getNumber());

        $nextYear = $this->factory()->create($ledger, party: $party, dateInvoice: new DateTimeImmutable('2027-01-03'));
        $this->assertSame('BIL-WEE2027-0001', $numbering->peek($nextYear));
    }

    public function testWorkflow(): void
    {
        $ledger = $this->createLedger();
        $workflow = $this->service(InvoiceWorkflow::class);
        $invoice = $this->factory()->create($ledger, party: $this->createParty($ledger));
        $this->factory()->addItem($invoice, 'A', 1000);
        $this->em()->flush();

        $this->assertTrue($workflow->submit($invoice));
        $this->assertTrue($workflow->approve($invoice));

        try {
            $workflow->transition($invoice, InvoiceStatus::Paid);
            $this->fail('Paid is set by payments only.');
        } catch (InvoiceTransitionException) {
        }

        $this->emission()->emit($invoice);
        $this->assertSame(InvoiceStatus::Emitted, $invoice->getStatus());
        $this->assertNotNull($invoice->getIssuerSnapshot());
        $this->assertSame('Client A', $invoice->getPartySnapshot()['name']);
        $this->assertSame($invoice->getDateInvoice()->modify('+30 days')->format('Y-m-d'), $invoice->getDateDue()->format('Y-m-d'));

        $this->expectException(AccountingException::class);
        $this->factory()->addItem($invoice, 'Late item', 100);
    }

    public function testPurchaseNeedsTheSupplierNumber(): void
    {
        $ledger = $this->createLedger();
        $supplier = $this->createParty($ledger, 'Supplier', customer: false, supplier: true);
        $purchase = $this->factory()->create($ledger, direction: InvoiceDirection::Purchase, party: $supplier);
        $this->factory()->addItem($purchase, 'Hosting', 5000);

        try {
            $this->emission()->emit($purchase);
            $this->fail('A purchase keeps its supplier number.');
        } catch (AccountingException) {
        }

        $purchase->setNumber('F-2026-77');
        $this->emission()->emit($purchase);
        $this->assertSame('F-2026-77', $purchase->getNumber());
        $this->assertSame(['61:5000/0', '4456:1000/0', '401:0/6000'], $this->describeLines($this->entryOf($purchase)));
    }

    public function testSaleBooking(): void
    {
        $ledger = $this->createLedger();
        $invoice = $this->emitSale($ledger, $this->createParty($ledger), 10000);
        $entry = $this->entryOf($invoice);

        $this->assertSame(['706:0/10000', '4457:0/2000', '411:12000/0'], $this->describeLines($entry));
        $this->assertSame('S_DOM_2000', $entry->getLines()[1]->getVatCode());
        $this->assertSame($invoice->getParty(), $entry->getLines()[2]->getParty());
        $this->assertSame($invoice->getNumber(), $entry->getPieceReference());

        // Booked once.
        $this->assertSame($entry, $this->service(InvoiceAccountingService::class)->book($invoice));
    }

    public function testMultiRateWithDiscount(): void
    {
        $ledger = $this->createLedger();
        $invoice = $this->factory()->create($ledger, party: $this->createParty($ledger));
        $this->factory()->addItem($invoice, 'Service', 10000, vatRate: 2100);
        $this->factory()->addItem($invoice, 'Book', 10000, vatRate: 600, goods: true);
        $this->factory()->addItem($invoice, 'Training', 5000, vatRate: 2100, accountNumber: '7061');
        $invoice->setPriceDiscount(5000, PriceUnit::Money);
        $this->em()->flush();
        $this->emission()->emit($invoice);

        $entry = $this->entryOf($invoice);
        $this->assertTrue($entry->isBalanced());
        $this->assertSame($invoice->calcPriceFinal(), $entry->getLines()->last()->getDebit());
        // 50.00 off spread 30/20 on the 21 % and 6 % bases.
        $this->assertSame(
            ['706:0/8000', '7061:0/4000', '707:0/8000', '4457:0/2520', '4457:0/480', '411:23000/0'],
            $this->describeLines($entry)
        );
    }

    public function testIntraEuServiceSale(): void
    {
        $ledger = $this->createLedger('BE');
        $customer = $this->createParty($ledger, 'French Co', 'FR', 'FR12345678901');
        $invoice = $this->emitSale($ledger, $customer, 10000);

        $this->assertSame(VatKind::IntraEuServices, $invoice->getItems()->first()->getVatKind());
        $this->assertSame(10000, $invoice->calcPriceFinal());
        $this->assertSame(['706:0/10000', '411:10000/0'], $this->describeLines($this->entryOf($invoice)));

        $mentions = $this->service(JurisdictionRegistry::class)->forLedger($ledger)->getInvoiceMentions($invoice);
        $this->assertSame('mention.vat.reverse_charge', $mentions[0]->key);
    }

    public function testReverseChargePurchase(): void
    {
        $ledger = $this->createLedger('BE');
        $supplier = $this->createParty($ledger, 'US Software', 'US', null, customer: false, supplier: true);
        $purchase = $this->factory()->create($ledger, direction: InvoiceDirection::Purchase, party: $supplier)->setNumber('INV-9');
        $this->factory()->addItem($purchase, 'Licence', 10000, vatRate: 2100);
        $this->emission()->emit($purchase);

        $this->assertSame(10000, $purchase->calcPriceFinal());
        $this->assertSame(
            ['61:10000/0', '4456:2100/0', '4454:0/2100', '401:0/10000'],
            $this->describeLines($this->entryOf($purchase))
        );
    }

    public function testFranchiseSale(): void
    {
        $ledger = $this->createLedger();
        $ledger->setVatSubject(false);
        $invoice = $this->emitSale($ledger, $this->createParty($ledger), 10000);

        $this->assertSame(10000, $invoice->calcPriceFinal());
        $this->assertSame(VatKind::Franchise, $invoice->getItems()->first()->getVatKind());
    }

    public function testPurchaseOfANonVatSubjectCompany(): void
    {
        $ledger = $this->createLedger();
        $ledger->setVatSubject(false);
        $supplier = $this->createParty($ledger, 'Supplier', customer: false, supplier: true);
        $purchase = $this->factory()->create($ledger, direction: InvoiceDirection::Purchase, party: $supplier)->setNumber('S-9');
        $this->factory()->addItem($purchase, 'Software', 10000, vatRate: 2100);
        $this->emission()->emit($purchase);

        // The supplier's VAT is paid, and is a cost.
        $this->assertSame(12100, $purchase->calcPriceFinal());
        $this->assertSame(['61:12100/0', '401:0/12100'], $this->describeLines($this->entryOf($purchase)));
    }

    public function testVatOnPayments(): void
    {
        $ledger = $this->createLedger();
        $ledger->setVatOnPayments(true);
        $invoice = $this->emitSale($ledger, $this->createParty($ledger));

        $this->assertSame(['706:0/10000', '4458:0/2000', '411:12000/0'], $this->describeLines($this->entryOf($invoice)));
    }

    public function testQuotationToBill(): void
    {
        $ledger = $this->createLedger();
        $party = $this->createParty($ledger);
        $quotation = $this->factory()->create($ledger, InvoiceType::Quotation, party: $party);
        $this->factory()->addItem($quotation, 'Website', 300000);
        $this->emission()->emit($quotation);
        $this->service(InvoiceWorkflow::class)->accept($quotation);

        // A quotation is not booked.
        $this->assertNull($this->entryOf($quotation));

        $bill = $this->factory()->createBillFromQuotation($quotation);
        $this->em()->flush();

        $this->assertSame(InvoiceType::Bill, $bill->getType());
        $this->assertSame(360000, $bill->calcPriceFinal());
        $this->assertCount(1, $this->service(InvoiceRelationRepository::class)->findBy(['source' => $quotation]));
    }

    public function testFullCreditNoteIsBookedReversed(): void
    {
        $ledger = $this->createLedger();
        $bill = $this->emitSale($ledger, $this->createParty($ledger));
        $credit = $this->factory()->createCreditNote($bill);
        $this->em()->flush();
        $this->emission()->emit($credit);

        $this->assertStringStartsWith('CRE-', $credit->getNumber());
        $this->assertSame(12000, $credit->calcPriceFinal());
        $this->assertSame(['706:10000/0', '4457:2000/0', '411:0/12000'], $this->describeLines($this->entryOf($credit)));
        $this->assertSame(-1, $credit->getPaymentSign());
    }

    public function testCancelingAnEmittedDocumentReversesItsEntry(): void
    {
        $ledger = $this->createLedger();
        $bill = $this->emitSale($ledger, $this->createParty($ledger));
        $this->service(InvoiceWorkflow::class)->transition($bill, InvoiceStatus::Canceled);

        $entries = $this->em()->getRepository(\Wexample\SymfonyAccounting\Entity\JournalEntry::class)->findBy(['sourceId' => (string) $bill->getId()]);
        $this->assertCount(2, $entries);
        $this->assertSame($entries[0]->getTotalDebit(), $entries[1]->getTotalCredit());
    }

    public function testLatePenalties(): void
    {
        $ledger = $this->createLedger();
        $bill = $this->emitSale($ledger, $this->createParty($ledger), 100000, '2026-01-01', vatRate: 0);
        // Due 2026-01-31, paid 45 days later.
        $paidAt = new DateTimeImmutable('2026-03-17');
        $calculator = $this->service(LatePenaltyCalculator::class);

        $annual = $calculator->calculate($bill, $paidAt, new LatePenaltyPolicy(LatePenaltyPolicy::MODE_ANNUAL, 1200, 4000));
        $this->assertSame(45, $annual->daysLate);
        // 1000.00 × 12 % × 45 / 365 = 14.79.
        $this->assertSame(1479, $annual->getInterest());
        $this->assertSame(5479, $annual->getTotal());

        $monthly = $calculator->calculate($bill, $paidAt, new LatePenaltyPolicy(LatePenaltyPolicy::MODE_MONTHLY, 1000, 4000));
        // February entirely (100.00), then 17 of 31 March days (54.84).
        $this->assertSame([100000 / 10, 5484], array_column($monthly->lines, 'amount'));

        $this->assertNull($calculator->calculate($bill, new DateTimeImmutable('2026-01-20')));

        $penalty = $calculator->createPenaltyInvoice($bill, $paidAt);
        $this->em()->flush();
        $this->emission()->emit($penalty);
        $this->assertSame(['763:0/5479', '411:5479/0'], $this->describeLines($this->entryOf($penalty)));
    }

    public function testChecker(): void
    {
        $ledger = $this->createLedger();
        $bill = $this->emitSale($ledger, $this->createParty($ledger), date: '2026-01-01');
        $report = $this->service(CheckService::class)->check($bill);

        $this->assertTrue($report->has('invoice.overdue'));
        $this->assertTrue($report->has('invoice.missing_document'));
        $this->assertFalse($report->has('invoice.not_booked'));
    }

    public function testModel(): void
    {
        $ledger = $this->createLedger();
        $model = $this->factory()->create($ledger, party: $this->createParty($ledger));
        $model->setPeriod(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));
        $this->factory()->addItem($model, 'Monthly maintenance', 50000);
        $this->em()->flush();
        $this->service(InvoiceWorkflow::class)->transition($model, InvoiceStatus::Model);

        $march = $this->factory()->createFromModel($model, new DateTimeImmutable('2026-03-05'));

        $this->assertSame($model, $march->getModel());
        $this->assertSame('2026-03-31', $march->getPeriodEnd()->format('Y-m-d'));
        $this->assertSame(60000, $march->calcPriceFinal());
    }

    public function testDepositThenFinalBill(): void
    {
        $ledger = $this->createLedger();
        $party = $this->createParty($ledger);
        $quotation = $this->factory()->create($ledger, InvoiceType::Quotation, party: $party);
        $this->factory()->addItem($quotation, 'Website', 300000);
        $this->emission()->emit($quotation);

        // 30 % deposit.
        $deposit = $this->factory()->createDepositBill($quotation, 90000);
        $this->em()->flush();
        $this->emission()->emit($deposit);
        $this->assertSame(108000, $deposit->calcPriceFinal());
        $this->assertSame(['419:0/90000', '4457:0/18000', '411:108000/0'], $this->describeLines($this->entryOf($deposit)));

        $this->service(InvoiceWorkflow::class)->accept($quotation);
        $final = $this->factory()->createBillFromQuotation($quotation);
        $this->em()->flush();
        $this->emission()->emit($final);

        $this->assertSame(252000, $final->calcPriceFinal());
        $this->assertSame(
            ['706:0/300000', '419:90000/0', '4457:0/42000', '411:252000/0'],
            $this->describeLines($this->entryOf($final))
        );
    }

    public function testCreateFromLines(): void
    {
        $ledger = $this->createLedger();
        $invoice = $this->factory()->createFromLines($ledger, $this->createParty($ledger), [
            ['title' => 'Membership', 'unitPrice' => 3000, 'vatRate' => 0],
            ['title' => 'Ticket', 'unitPrice' => 1000, 'quantity' => 2, 'vatRate' => 2100],
        ], origin: 'cart');

        $this->assertSame('cart', $invoice->getOrigin());
        $this->assertSame(3000 + 2420, $invoice->calcPriceFinal());
    }
}
