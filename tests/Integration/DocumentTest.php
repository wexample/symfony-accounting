<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use Wexample\SymfonyAccounting\Service\Bank\AllocationService;
use Wexample\SymfonyAccounting\Service\Document\InvoiceDocumentDataBuilder;
use Wexample\SymfonyAccounting\Service\Document\PdfFactoryDocumentBuilder;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;

class DocumentTest extends AbstractAccountingTestCase
{
    public function testInvoiceDocument(): void
    {
        $ledger = $this->createLedger();
        $ledger->setName('Wexample SRL')->setLegalIdentifier('0123.456.749')->setPostalAddress('Rue Neuve 1')->setPostCode('1000')->setCity('Bruxelles');
        $bank = $this->createBankAccount($ledger)->setIban('BE68 5390 0754 7034')->setBic('GKCCBEBB')->setHolder('Wexample SRL');
        $ledger->setDefaultBankAccount($bank);
        $party = $this->createParty($ledger, 'Client SA');
        $party->setPostalAddress("Avenue Louise 10\nBoîte 3")->setPostCode('1050')->setCity('Ixelles');

        $factory = $this->service(InvoiceFactory::class);
        $invoice = $factory->create($ledger, party: $party, title: 'Website');
        $factory->addItem($invoice, 'Design', 50000, 150);
        $factory->addItem($invoice, 'Hosting', 10000, vatRate: 600);
        $this->em()->flush();
        $this->service(InvoiceEmissionService::class)->emit($invoice);
        $this->service(AllocationService::class)->allocate($this->createTransaction($bank, 10000), $invoice);

        $data = $this->service(InvoiceDocumentDataBuilder::class)->build($invoice, 'en');

        $this->assertSame('Invoice', $data['title']);
        $this->assertSame('Wexample SRL', $data['issuer']['name']);
        $this->assertSame(['Avenue Louise 10', 'Boîte 3', '1050 Ixelles', 'BE'], $data['client']['addressLines']);
        $this->assertSame('1.5', $data['items'][0]['quantity']);
        $this->assertSame(75000, $data['items'][0]['total']['amount']);
        $this->assertCount(2, $data['totals']['vatLines']);
        $this->assertSame(100600, $data['totals']['final']['amount']);
        $this->assertSame(90600, $data['totals']['due']['amount']);
        $this->assertSame('mention.payment.late_penalties', $data['mentions'][0]['key']);
        $this->assertStringContainsString('Payable within 30 days', $data['mentions'][0]['text']);
        $this->assertStringStartsWith("BCD\n002\n1\nSCT\nGKCCBEBB\nWexample SRL\nBE68539007547034\nEUR906.00", $data['payment']['epcQr']);

        $document = $this->service(PdfFactoryDocumentBuilder::class)->build($data, 'en');
        $this->assertSame('doc', $document['type']);
        $this->assertSame('section', $document['content'][0]['type']);

        // Kept for the schema check run beside the tests.
        file_put_contents(sys_get_temp_dir().'/accounting-invoice-document.json', json_encode(['document' => $document], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
