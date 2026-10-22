<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Entity\BankStatement;
use Wexample\SymfonyAccounting\Entity\ImportPattern;
use Wexample\SymfonyAccounting\Enum\AllocationStatus;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyAccounting\Repository\JournalEntryRepository;
use Wexample\SymfonyAccounting\Service\Bank\AllocationAccountingService;
use Wexample\SymfonyAccounting\Service\Bank\AllocationService;
use Wexample\SymfonyAccounting\Service\Bank\BankImportService;
use Wexample\SymfonyAccounting\Service\Bank\MatchingService;
use Wexample\SymfonyAccounting\Service\Bank\ProviderBalanceImporter;
use Wexample\SymfonyAccounting\Service\Bank\ReconciliationService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Ledger\LetteringService;
use Wexample\SymfonyCheck\Service\CheckService;
use Wexample\SymfonyPayment\Service\PaymentService;
use Wexample\SymfonyRemotePayment\Class\BalanceMovement;
use Wexample\SymfonyRemotePayment\Class\FakePaymentProvider;
use Wexample\SymfonyRemotePayment\Enum\BalanceMovementType;

class BankTest extends AbstractAccountingTestCase
{
    private function allocations(): AllocationService
    {
        return $this->service(AllocationService::class);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/Bank/'.$name);
    }

    public function testImportNeverDuplicates(): void
    {
        $ledger = $this->createLedger();
        $bank = $this->createBankAccount($ledger);
        $import = $this->service(BankImportService::class);
        $options = ['date_column' => 'Date', 'label_columns' => ['Libellé'], 'debit_column' => 'Débit', 'credit_column' => 'Crédit'];

        $first = $import->importContent($bank, $this->fixture('generic.csv'), 'csv', $options);
        $this->assertSame(3, $first->count());

        $second = $import->importContent($bank, $this->fixture('generic.csv'), 'csv', $options);
        $this->assertSame(0, $second->count());
        $this->assertSame(3, $second->duplicates);

        $camt = $import->importContent($bank, $this->fixture('camt053.xml'));
        $this->assertSame(2, $camt->count());
        $this->assertSame(1, $camt->balances);
        $this->assertSame(0, $import->importContent($bank, $this->fixture('camt053.xml'))->count());
    }

    public function testFullPayment(): void
    {
        $ledger = $this->createLedger();
        $bank = $this->createBankAccount($ledger);
        $invoice = $this->emitSale($ledger, $this->createParty($ledger));
        $line = $this->createTransaction($bank, 12000);

        $allocation = $this->allocations()->allocate($line, $invoice);

        $this->assertSame(InvoiceStatus::Paid, $invoice->getStatus());
        $this->assertSame('2026-03-15', $invoice->getDatePaid()->format('Y-m-d'));
        $this->assertTrue($line->isSettled());
        $entry = $this->service(AllocationAccountingService::class)->findEntry($allocation);
        $this->assertSame(['5121:12000/0', '411:0/12000'], $this->describeLines($entry));
        $this->assertSame('BQ', $entry->getJournal()->getCode());
    }

    public function testOneLinePayingTwoDocuments(): void
    {
        $ledger = $this->createLedger();
        $bank = $this->createBankAccount($ledger);
        $party = $this->createParty($ledger);
        $first = $this->emitSale($ledger, $party, 5000);
        $second = $this->emitSale($ledger, $party, 10000);
        $line = $this->createTransaction($bank, 18000);

        $this->allocations()->allocate($line, $first);
        $this->allocations()->allocate($line, $second);

        $this->assertSame(InvoiceStatus::Paid, $first->getStatus());
        $this->assertSame(InvoiceStatus::Paid, $second->getStatus());

        // Nothing left on the line.
        $this->expectException(AccountingException::class);
        $this->allocations()->allocate($line, $this->emitSale($ledger, $party, 100));
    }

    public function testPartialPaymentAndOverAllocation(): void
    {
        $ledger = $this->createLedger();
        $bank = $this->createBankAccount($ledger);
        $invoice = $this->emitSale($ledger, $this->createParty($ledger));

        $this->allocations()->allocate($this->createTransaction($bank, 5000), $invoice);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->getStatus());
        $this->assertSame(7000, $invoice->calcRemainingAmount());

        $this->allocations()->allocate($this->createTransaction($bank, 9000, '2026-03-20'), $invoice, 9000);
        $report = $this->service(CheckService::class)->check($invoice);
        $this->assertTrue($report->has('invoice.overpaid'));
    }

    public function testDirectionMismatchIsRejected(): void
    {
        $ledger = $this->createLedger();
        $bank = $this->createBankAccount($ledger);
        $invoice = $this->emitSale($ledger, $this->createParty($ledger));

        $this->expectException(AccountingException::class);
        $this->allocations()->allocate($this->createTransaction($bank, -12000), $invoice);
    }

    public function testExclusionPreventsProposals(): void
    {
        $ledger = $this->createLedger();
        $bank = $this->createBankAccount($ledger);
        $invoice = $this->emitSale($ledger, $this->createParty($ledger));
        $line = $this->createTransaction($bank, 12000, label: 'VIR SOMEONE');
        $matching = $this->service(MatchingService::class);

        $this->assertSame(1, $matching->run($ledger));
        $allocation = $line->getAllocations()->first();
        $this->assertSame(AllocationStatus::Pending, $allocation->getStatus());
        $this->assertSame('exact_amount', $allocation->getOrigin());

        $this->allocations()->exclude($allocation);
        $this->assertSame(InvoiceStatus::Emitted, $invoice->getStatus());
        $this->assertSame(0, $matching->run($ledger));
    }

    public function testExactAmountMatcherWindow(): void
    {
        $ledger = $this->createLedger(fiscalYearStart: '2025-01-01');
        $this->service(\Wexample\SymfonyAccounting\Service\Ledger\FiscalYearService::class)->createNext($ledger);
        $bank = $this->createBankAccount($ledger);
        $party = $this->createParty($ledger);
        $old = $this->emitSale($ledger, $party, 10000, '2025-01-05');
        $line = $this->createTransaction($bank, 12000, '2026-03-15');
        $date = $line->getDate();

        // More than a year apart: not proposed.
        $this->assertSame([], $this->service(MatchingService::class)->propose($line));
        $this->assertEquals($date, $line->getDate());

        $recent = $this->emitSale($ledger, $party, 10000, '2026-03-01');
        $proposals = $this->service(MatchingService::class)->propose($line);
        $this->assertSame($recent, $proposals[0]->invoice);
        $this->assertNotSame($old, $proposals[0]->invoice);
    }

    public function testReferenceAndPatternMatchers(): void
    {
        $ledger = $this->createLedger();
        $bank = $this->createBankAccount($ledger);
        $invoice = $this->emitSale($ledger, $this->createParty($ledger), 10000);
        $byReference = $this->createTransaction($bank, 12000, label: 'VIR PAYMENT '.$invoice->getNumber());

        $fees = (new ImportPattern())->setLedger($ledger)->setPattern('^FRAIS')->setAccountNumber('627')->setSign(-1)->setAutoValidate(true);
        $this->em()->persist($fees);
        $this->em()->flush();
        $feeLine = $this->createTransaction($bank, -2050, label: 'FRAIS DE GESTION');

        $this->assertSame(2, $this->service(MatchingService::class)->run($ledger));
        $this->assertSame($invoice, $byReference->getAllocations()->first()->getInvoice());
        $this->assertSame('627', $feeLine->getAllocations()->first()->getAccountNumber());
        $this->assertSame(AllocationStatus::Validated, $feeLine->getAllocations()->first()->getStatus());
    }

    public function testTransfers(): void
    {
        $ledger = $this->createLedger();
        $main = $this->createBankAccount($ledger);
        $savings = $this->createBankAccount($ledger, 'Savings', '5122', journalCode: 'BQ');
        $out = $this->createTransaction($main, -50000, '2026-04-01', 'VIR VERS EPARGNE');
        $in = $this->createTransaction($savings, 50000, '2026-04-02', 'VIR DEPUIS COURANT');

        try {
            $this->allocations()->linkTransfer($out, $this->createTransaction($savings, 40000));
            $this->fail('Amounts must be opposite.');
        } catch (AccountingException) {
        }

        $this->assertSame(1, $this->service(MatchingService::class)->run($ledger, $main));
        $this->assertSame($in, $out->getTransferPeer());
        $this->assertTrue($out->isSettled());

        $entries = $this->service(JournalEntryRepository::class)->findBy(['sourceType' => AllocationAccountingService::SOURCE_TRANSFER]);
        $this->assertCount(2, $entries);
        $this->assertSame(['5121:0/50000', '580:50000/0'], $this->describeLines($entries[0]));
        $this->assertSame(['5122:50000/0', '580:0/50000'], $this->describeLines($entries[1]));
    }

    public function testStripeCsvAndApiDoNotDuplicate(): void
    {
        $ledger = $this->createLedger();
        $stripe = $this->createBankAccount($ledger, 'Stripe', '517', 'fake');
        $this->service(BankImportService::class)->importContent($stripe, $this->fixture('stripe.csv'));

        /** @var FakePaymentProvider $provider */
        $provider = $this->service(FakePaymentProvider::class);
        $provider->addMovement(new BalanceMovement('txn_100', BalanceMovementType::Charge, 10000, 175, 'EUR', new DateTimeImmutable('2026-01-10 09:12')));
        $provider->addMovement(new BalanceMovement('txn_102', BalanceMovementType::Charge, 5000, 100, 'EUR', new DateTimeImmutable('2026-01-20')));

        $result = $this->service(ProviderBalanceImporter::class)->import($stripe, new DateTimeImmutable('2026-01-01'));

        // Only txn_102 is new: its gross and its fee.
        $this->assertSame(2, $result->count());
        $this->assertSame(2, $result->duplicates);

        $lines = $this->service(BankTransactionRepository::class)->findForPeriod($stripe);
        $this->assertCount(5, $lines);

        foreach ($lines as $line) {
            if ($line->getAmount() < 0 && ($line->getMetadata()['provider_fee'] ?? false)) {
                $this->assertTrue($line->isSettled());
                $this->assertSame('627', $line->getAllocations()->first()->getAccountNumber());
            }
        }
    }

    public function testOnlinePaymentOfAnInvoice(): void
    {
        $ledger = $this->createLedger();
        $stripe = $this->createBankAccount($ledger, 'Stripe', '517', 'fake');
        $invoice = $this->emitSale($ledger, $this->createParty($ledger));

        // The customer pays the invoice online.
        $payments = $this->service(PaymentService::class);
        $payment = $payments->getOrCreateForPayable($invoice, 'card');
        $payments->initiate($payment);
        /** @var FakePaymentProvider $provider */
        $provider = $this->service(FakePaymentProvider::class);
        $payments->handleNotification($provider->succeed($payment->getProviderReference(), new DateTimeImmutable('2026-03-05')));

        // Later, the Stripe balance is imported and matched.
        $this->service(ProviderBalanceImporter::class)->import($stripe, new DateTimeImmutable('2026-03-01'));
        $this->service(MatchingService::class)->run($ledger);

        $this->assertSame(InvoiceStatus::Paid, $invoice->getStatus());
        $this->assertSame('provider_payment', $invoice->getAllocations()->first()->getOrigin());
    }

    public function testReconciliation(): void
    {
        $ledger = $this->createLedger();
        $bank = $this->createBankAccount($ledger);
        $reconciliation = $this->service(ReconciliationService::class);

        foreach (['2026-01-31' => 100000, '2026-02-28' => 112000, '2026-03-31' => 99999] as $date => $balance) {
            $this->em()->persist((new BankStatement())->setBankAccount($bank)->setDate(new DateTimeImmutable($date))->setBalance($balance));
        }
        $this->createTransaction($bank, 15000, '2026-02-10');
        $this->createTransaction($bank, -3000, '2026-02-20');
        $this->createTransaction($bank, -12000, '2026-03-05');

        $this->assertSame(115000, $reconciliation->getBalanceAt($bank, new DateTimeImmutable('2026-02-15')));

        $checks = $reconciliation->checkStatements($bank);
        $this->assertTrue($checks[1]->isReconciled());
        $this->assertFalse($checks[2]->isReconciled());
        $this->assertSame(-1, $checks[2]->getDifference());
    }

    public function testLettering(): void
    {
        $ledger = $this->createLedger();
        $bank = $this->createBankAccount($ledger);
        $party = $this->createParty($ledger);
        // Two documents paid by one line…
        $a = $this->emitSale($ledger, $party, 10000);
        $b = $this->emitSale($ledger, $party, 5000);
        $transfer = $this->createTransaction($bank, 18000);
        $this->allocations()->allocate($transfer, $a);
        $this->allocations()->allocate($transfer, $b);
        // …and one document paid by two lines.
        $c = $this->emitSale($ledger, $party, 20000);
        $this->allocations()->allocate($this->createTransaction($bank, 10000, '2026-04-01'), $c);
        $this->allocations()->allocate($this->createTransaction($bank, 14000, '2026-04-10'), $c);
        // And one unpaid.
        $unpaid = $this->emitSale($ledger, $party, 999);

        $lettering = $this->service(LetteringService::class);
        $this->assertSame(2, $lettering->letterLedger($ledger));

        $letterOf = fn ($invoice) => $this->entryOf($invoice)->getLines()->last()->getLetter();
        $this->assertSame($letterOf($a), $letterOf($b));
        $this->assertNotSame($letterOf($a), $letterOf($c));
        $this->assertSame(['A', 'B'], [$letterOf($a), $letterOf($c)]);
        $this->assertNull($letterOf($unpaid));

        // Idempotent.
        $this->assertSame(0, $lettering->letterLedger($ledger));

        // Removing a payment unletters its group.
        $this->allocations()->exclude($c->getAllocations()->first());
        $lettering->letterLedger($ledger);
        $this->assertNull($letterOf($c));
    }

    public function testVatOnPaymentsBecomesDueWhenPaid(): void
    {
        $ledger = $this->createLedger();
        $ledger->setVatOnPayments(true);
        $bank = $this->createBankAccount($ledger);
        $invoice = $this->emitSale($ledger, $this->createParty($ledger));
        $allocation = $this->allocations()->allocate($this->createTransaction($bank, 6000), $invoice);

        $this->assertSame(
            ['5121:6000/0', '411:0/6000', '4458:1000/0', '4457:0/1000'],
            $this->describeLines($this->service(AllocationAccountingService::class)->findEntry($allocation))
        );
    }

    public function testPurchasePaymentAndDirectAllocation(): void
    {
        $ledger = $this->createLedger();
        $bank = $this->createBankAccount($ledger);
        $supplier = $this->createParty($ledger, 'Supplier', customer: false, supplier: true);
        $factory = $this->service(InvoiceFactory::class);
        $purchase = $factory->create($ledger, direction: InvoiceDirection::Purchase, party: $supplier)->setNumber('S-1');
        $factory->addItem($purchase, 'Hosting', 5000);
        $this->service(InvoiceEmissionService::class)->emit($purchase);

        $allocation = $this->allocations()->allocate($this->createTransaction($bank, -6000), $purchase);
        $this->assertSame(['5121:0/6000', '401:6000/0'], $this->describeLines($this->service(AllocationAccountingService::class)->findEntry($allocation)));

        $direct = $this->allocations()->allocateToAccount($this->createTransaction($bank, -1210, label: 'PARKING'), '6251', vatRate: 2100);
        $this->assertSame(
            ['5121:0/1210', '6251:1000/0', '4456:210/0'],
            $this->describeLines($this->service(AllocationAccountingService::class)->findEntry($direct))
        );
    }
}
