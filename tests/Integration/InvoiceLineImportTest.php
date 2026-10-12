<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use Wexample\SymfonyAccounting\Entity\InvoiceItem;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceLineImportService;

class InvoiceLineImportTest extends AbstractAccountingTestCase
{
    private function draft(): \Wexample\SymfonyAccounting\Entity\Invoice
    {
        $ledger = $this->createLedger();

        return $this->service(InvoiceFactory::class)->create($ledger, party: $this->createParty($ledger, 'Client SA'));
    }

    public function testRowsAreReadAsPeopleTypeThem(): void
    {
        $invoice = $this->draft();
        $columns = ['title' => 0, 'quantity' => 1, 'unitPrice' => 2, 'vatRate' => 3, 'description' => 4];

        $items = $this->service(InvoiceLineImportService::class)->import($invoice, [
            ['Design', '1,5', '1 234,50 €', '20 %', 'Home page'],
            ['', '', '', '', ''],
            ['Printing', '', '1,234.00', '0.055', null],
            ['Hosting', '12', '9.90', '', ''],
        ], $columns);

        $this->assertCount(3, $items);
        $this->assertSame(['Design', 'Printing', 'Hosting'], array_map(fn (InvoiceItem $item) => $item->getTitle(), $items));
        $this->assertSame([123450, 123400, 990], array_map(fn (InvoiceItem $item) => $item->getPriceRaw(), $items));
        $this->assertSame([150, 100, 1200], array_map(fn (InvoiceItem $item) => $item->getQuantity(), $items));
        $this->assertSame([2000, 550], [$items[0]->getPriceVat(), $items[1]->getPriceVat()]);
        $this->assertSame('Home page', $items[0]->getDescription());
        $this->assertNull($items[1]->getDescription());
        $this->assertNotNull($items[2]->getPriceVat());
    }

    public function testOneBadRowAddsNothing(): void
    {
        $invoice = $this->draft();

        try {
            $this->service(InvoiceLineImportService::class)->import($invoice, [
                ['Design', '100'],
                ['Printing', 'free'],
            ], ['title' => 0, 'unitPrice' => 1]);
            $this->fail('No exception.');
        } catch (AccountingException $exception) {
            $this->assertSame('Row 2: "free" is no price.', $exception->getMessage());
        }

        $this->assertCount(0, $invoice->getItems());
    }

    public function testTheTitleAndThePriceNeedAColumn(): void
    {
        $this->expectException(AccountingException::class);

        $this->service(InvoiceLineImportService::class)->import($this->draft(), [['Design']], ['title' => 0]);
    }
}
