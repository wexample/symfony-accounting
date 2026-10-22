<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Event\InvoiceDocumentEvent;
use Wexample\SymfonyAccounting\Service\Bank\AllocationService;
use Wexample\SymfonyAccounting\Service\Document\InvoiceDocumentDataBuilder;
use Wexample\SymfonyAccounting\Service\Document\InvoicePdfService;
use Wexample\SymfonyAccounting\Service\Document\PdfFactoryDocumentBuilder;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;

class DocumentTest extends AbstractAccountingTestCase
{
    public function testInvoiceDocument(): void
    {
        $ledger = $this->createLedger();
        $ledger->getOrganization()->setName('Wexample SRL')->setLegalIdentifier('0123.456.749')->setAddress($this->address('Rue Neuve 1', '1000', 'Bruxelles', 'BE'));
        $bank = $this->createBankAccount($ledger)->setIban('BE68 5390 0754 7034')->setBic('GKCCBEBB')->setHolder('Wexample SRL');
        $ledger->setDefaultBankAccount($bank);
        $party = $this->createParty($ledger, 'Client SA');
        $party->getOrganization()->setAddress($this->address("Avenue Louise 10\nBoîte 3", '1050', 'Ixelles', 'BE'));

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
        $this->assertSame(['Avenue Louise 10', 'Boîte 3', '1050 Ixelles', 'Belgium'], $data['client']['addressLines']);
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

    public function testTheDocumentNamesItsPartsAndItsThemeDressesThem(): void
    {
        $invoice = $this->emitDocumented();
        $prepared = $this->service(InvoicePdfService::class)->prepare($invoice, 'en');

        $this->assertSame(['parties', 'dates', 'lines', 'totals', 'payment', 'terms'], $this->roles($prepared->document));
        $this->assertSame('03/01/2026', $prepared->data['dates']['invoice']['formatted']);
        $this->assertSame('qrCode', $this->section($prepared->document, 'payment')[1]['type']);
        $this->assertSame([['paragraph']], array_map(fn ($node) => [$node['type']], $prepared->document['meta']['imprint']));
        $this->assertStringContainsString('section[data-role="parties"]', $prepared->theme->stylesheet);
        $this->assertSame(['pagination-before' => 'Page ', 'pagination-between' => ' / '], $prepared->theme->variables);
    }

    public function testThePdfCarriesItsDataAndSaysWhoMadeItAndWhen(): void
    {
        $invoice = $this->emitDocumented();
        $prepared = $this->service(InvoicePdfService::class)->prepare($invoice, 'fr_BE');

        $this->assertSame('fr-BE', $prepared->document['language']);
        $this->assertSame($prepared->data['issuer']['name'], $prepared->document['author']);
        $this->assertSame($invoice->getDateEmitted()->format(DATE_ATOM), $prepared->document['created']);

        [$attachment] = $prepared->attachments;
        $this->assertSame('invoice.json', $attachment->name);
        $this->assertSame('Data', $attachment->relationship);
        $this->assertSame($invoice->getNumber(), json_decode($attachment->content, true)['number']);
        $this->assertNull($prepared->output);
    }

    public function testACreditNoteAsksNobodyToPay(): void
    {
        $invoice = $this->emitDocumented();
        $credit = $this->service(InvoiceFactory::class)->createCreditNote($invoice);
        $this->em()->flush();
        $this->service(InvoiceEmissionService::class)->emit($credit);

        $document = $this->service(InvoicePdfService::class)->prepare($credit, 'en')->document;

        $this->assertNotContains('payment', $this->roles($document));
        $this->assertSame('Credits invoice : '.$invoice->getNumber().' (03/01/2026)', $this->section($document, 'dates')[1]['content'][0]['text']);
    }

    public function testAPaidDocumentSaysSo(): void
    {
        $invoice = $this->emitDocumented();
        $bank = $invoice->getLedger()->getDefaultBankAccount();
        $this->service(AllocationService::class)->allocate($this->createTransaction($bank, $invoice->calcRemainingAmount(), '2026-03-10'), $invoice);
        $this->em()->flush();

        $prepared = $this->service(InvoicePdfService::class)->prepare($invoice, 'en');
        $settlement = $this->section($prepared->document, 'settlement');

        $this->assertSame('Paid', $settlement[0]['content'][0]['text']);
        $this->assertNotContains('qrCode', array_column($this->section($prepared->document, 'payment'), 'type'));
    }

    public function testTheApplicationHasTheLastWord(): void
    {
        $this->service(EventDispatcherInterface::class)->addListener(InvoiceDocumentEvent::class, function (InvoiceDocumentEvent $event) {
            $event->theme = $event->theme->withStylesheet('h1 { color: #c00; }');
            $event->document['content'][0]['content'][] = ['type' => 'section', 'attrs' => ['role' => 'brand'], 'content' => [['type' => 'paragraph']]];
        });

        $prepared = $this->service(InvoicePdfService::class)->prepare($this->emitDocumented(), 'en');

        $this->assertStringEndsWith('h1 { color: #c00; }', $prepared->theme->stylesheet);
        $this->assertContains('brand', $this->roles($prepared->document));
    }

    public function testACreditNoteIsWhatTheBillNoLongerAsks(): void
    {
        $bill = $this->emitDocumented();   // 500,00 + 20 % = 600,00
        $credit = $this->service(InvoiceFactory::class)->createCreditNote($bill, 20000);   // 200,00 + 20 %
        $this->em()->flush();
        $this->service(InvoiceEmissionService::class)->emit($credit);

        $this->assertSame(24000, $bill->calcCreditedAmount());
        $this->assertSame(36000, $bill->calcRemainingAmount());

        $prepared = $this->service(InvoicePdfService::class)->prepare($bill, 'en');
        $totals = array_map(
            fn (array $row) => array_map(fn (array $cell) => $cell['content'][0]['content'][0]['text'], $row['content']),
            $this->section($prepared->document, 'totals')[0]['content']
        );

        $this->assertSame(['Credit note '.$credit->getNumber(), '-€240.00'], $totals[3]);
        $this->assertSame(['Amount due', '€360.00'], $totals[4]);
        $this->assertStringContainsString("\nEUR360.00\n", $prepared->data['payment']['epcQr']);
        $this->assertCount(2, $this->section($prepared->document, 'lines')[1]['content'], 'The credit note is no line of the bill.');

        // The rest paid: the bill is settled, the credit note counting as paid back.
        $bank = $bill->getLedger()->getDefaultBankAccount();
        $this->service(AllocationService::class)->allocate($this->createTransaction($bank, 36000), $bill);
        $this->em()->flush();

        $this->assertSame(InvoiceStatus::Paid, $bill->getStatus());
    }

    public function testAFullyCreditedBillIsSettledWithoutPayment(): void
    {
        $bill = $this->emitDocumented();
        $credit = $this->service(InvoiceFactory::class)->createCreditNote($bill);
        $this->em()->flush();
        $this->service(InvoiceEmissionService::class)->emit($credit);

        $this->assertSame(0, $bill->calcRemainingAmount());
        $this->assertSame(InvoiceStatus::Paid, $bill->getStatus());
        $this->assertNull($this->service(InvoicePdfService::class)->prepare($bill, 'en')->data['payment']['epcQr']);
    }

    private function emitDocumented(): Invoice
    {
        $ledger = $this->createLedger();
        $ledger->getOrganization()->setName('Wexample SRL')->setAddress($this->address('Rue Neuve 1', '1000', 'Bruxelles', 'BE'));
        $bank = $this->createBankAccount($ledger)->setIban('BE68 5390 0754 7034')->setBic('GKCCBEBB');
        $ledger->setDefaultBankAccount($bank);
        $factory = $this->service(InvoiceFactory::class);
        $invoice = $factory->create($ledger, party: $this->createParty($ledger, 'Client SA'), title: 'Website', dateInvoice: new DateTimeImmutable('2026-03-01'));
        $factory->addItem($invoice, 'Design', 50000, 100);
        $this->em()->flush();

        return $this->service(InvoiceEmissionService::class)->emit($invoice);
    }

    /**
     * @return list<string>
     */
    private function roles(array $document): array
    {
        return array_column(array_column(array_filter($document['content'][0]['content'], fn ($node) => 'section' === $node['type']), 'attrs'), 'role');
    }

    private function section(
        array $document,
        string $role
    ): array {
        foreach ($document['content'][0]['content'] as $node) {
            if ('section' === $node['type'] && $role === $node['attrs']['role']) {
                return $node['content'];
            }
        }

        $this->fail('No section '.$role);
    }
}
