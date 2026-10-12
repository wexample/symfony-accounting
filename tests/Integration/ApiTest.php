<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Tests\Fixtures\App\Access\FirmGroup;
use Wexample\SymfonyAccounting\Tests\Fixtures\App\Access\FirmGroupResolver;
use Wexample\SymfonyApi\Helper\ApiResponseNormHelper;
use Wexample\SymfonyRemoteDocumentReader\Service\DocumentReaderClient;

/**
 * The endpoints of the books, called as a front-end calls them: signed in,
 * the answers held to the API norm, the ledgers out of reach not existing.
 */
class ApiTest extends AbstractAccountingTestCase
{
    private KernelBrowser $client;

    private Ledger $acme;

    private Ledger $globex;

    protected function setUp(): void
    {
        parent::setUp();

        FirmGroupResolver::$groups = [
            'firm:acme' => new FirmGroup('acme', [
                'anna' => ['accountant', 'client'],
                'chris' => ['client'],
            ]),
        ];

        $this->acme = $this->createLedger()->setExternalReference('firm:acme');
        $this->globex = $this->createLedger()->setExternalReference('firm:globex');
        $this->em()->flush();

        $this->client = static::getContainer()->get('test.client');
        // The schema lives in this kernel's in-memory database: keep it between requests.
        $this->client->disableReboot();
    }

    public function testAReaderSeesTheLedgersTheirGroupsHold(): void
    {
        $this->signIn('root');
        $this->assertCount(2, $this->get('api/ledger/list')['data']['items']);

        $this->signIn('anna');
        $items = $this->get('api/ledger/list')['data']['items'];
        $this->assertSame([(string) $this->acme->getId()], array_column(array_column($items, 'entity'), 'id'));

        $this->get('api/invoice/list?ledger='.$this->globex->getId(), 404);
        $this->get('api/ledger/show/'.$this->globex->getId(), 404);
    }

    public function testADocumentIsDraftedFilledEmittedAndCredited(): void
    {
        $this->signIn('anna');
        $party = $this->createParty($this->acme, 'Client SA');

        $draft = $this->post('api/invoice/create', [
            'ledger' => (string) $this->acme->getId(),
            'party' => (string) $party->getId(),
            'title' => 'Website',
            'dateInvoice' => '2026-03-01',
        ])['data'];
        $id = $draft['entity']['id'];
        $this->assertSame('draft', $draft['entity']['status']);
        $this->assertSame('Client SA', $draft['relationships'][$draft['entity']['party']]['entity']['name']);

        $filled = $this->post('api/invoice-item/create', ['invoice' => $id, 'title' => 'Design', 'unitPrice' => 50000, 'quantity' => 200])['data'];
        $this->assertSame(100000, $filled['entity']['amountNet']);
        $this->assertCount(1, $filled['entity']['items']);

        $emitted = $this->post('api/invoice/emit/'.$id)['data']['entity'];
        $this->assertNotNull($emitted['number']);
        $this->assertSame(120000, $emitted['amountDue']);

        $this->post('api/invoice-item/create', ['invoice' => $id, 'title' => 'Late', 'unitPrice' => 100], 400);

        $credit = $this->post('api/invoice/credit-note/'.$id, ['amount' => 20000])['data']['entity'];
        $this->assertSame('credit_note', $credit['type']);
        $this->assertSame(96000, $this->get('api/invoice/show/'.$id)['data']['entity']['amountDue']);

        $page = $this->get('api/invoice/list?ledger='.$this->acme->getId().'&year=2026&type=bill')['data'];
        $this->assertSame(1, $page['pagination']['total']);
        $this->assertSame($id, $page['items'][0]['entity']['id']);
    }

    public function testAPaymentIsAllocatedThenTakenBack(): void
    {
        $this->signIn('anna');
        $invoice = $this->emitSale($this->acme, $this->createParty($this->acme));
        $bank = $this->createBankAccount($this->acme);
        $line = $this->createTransaction($bank, $invoice->calcRemainingAmount());

        $lines = $this->get('api/bank-transaction/list?ledger='.$this->acme->getId().'&year=2026&unallocated=1')['data'];
        $this->assertSame(1, $lines['pagination']['total']);

        $allocation = $this->post('api/allocation/create', ['transaction' => (string) $line->getId(), 'invoice' => (string) $invoice->getId()])['data'];
        $this->assertSame('validated', $allocation['entity']['status']);
        $this->assertSame('paid', $this->get('api/invoice/show/'.$invoice->getId())['data']['entity']['status']);
        $this->assertSame(0, $this->get('api/bank-transaction/list?ledger='.$this->acme->getId().'&year=2026&unallocated=1')['data']['pagination']['total']);

        $this->request('DELETE', 'api/allocation/delete/'.$allocation['entity']['id']);
        $this->assertSame('emitted', $this->get('api/invoice/show/'.$invoice->getId())['data']['entity']['status']);
    }

    public function testAClientReadsButDoesNotWrite(): void
    {
        $this->signIn('chris');

        $this->get('api/party/list?ledger='.$this->acme->getId());
        $this->post('api/invoice/create', ['ledger' => (string) $this->acme->getId()], 404);
    }

    public function testTheBooksAreReadAsTheyStand(): void
    {
        $this->signIn('anna');
        $this->emitSale($this->acme, $this->createParty($this->acme));

        $this->assertGreaterThan(0, $this->get('api/account/list?ledger='.$this->acme->getId().'&search=7')['data']['pagination']['total']);
        $this->assertSame(1, $this->get('api/journal-entry/list?ledger='.$this->acme->getId().'&year=2026')['data']['pagination']['total']);

        $balance = $this->get('api/trial-balance/show?ledger='.$this->acme->getId().'&year=2026')['data'];
        $this->assertTrue($balance['balanced']);
        $this->assertSame($balance['totalDebit'], $balance['totalCredit']);
    }

    public function testPartiesAreWrittenFieldByField(): void
    {
        $this->signIn('anna');
        $france = $this->country('FR');

        $party = $this->post('api/party/create', [
            'ledger' => (string) $this->acme->getId(),
            'name' => 'Atelier SA',
            'email' => 'hello@atelier.example',
            'country' => (string) $france->getId(),
        ])['data']['entity'];
        $this->assertSame('Atelier SA', $party['name']);
        $this->assertNotNull($party['customerCode']);

        $updated = $this->post('api/party/update/'.$party['id'], ['phone' => '01 23 45 67 89', 'email' => null])['data']['entity'];
        $this->assertSame('Atelier SA', $updated['name'], 'A field left out is kept.');
        $this->assertSame('01 23 45 67 89', $updated['phone']);
        $this->assertNull($updated['email'], 'A field sent as null is emptied.');
    }

    public function testAQuotationBecomesABillAndADraftChanges(): void
    {
        $this->signIn('anna');
        // Written before the requests: each request resets the entity manager the test holds.
        $emitted = $this->emitSale($this->acme, $this->createParty($this->acme));
        $quotation = $this->post('api/invoice/create', ['ledger' => (string) $this->acme->getId(), 'party' => (string) $this->createParty($this->acme)->getId(), 'type' => 'quotation', 'dateInvoice' => '2026-03-01'])['data']['entity'];
        $this->post('api/invoice-item/create', ['invoice' => $quotation['id'], 'title' => 'Design', 'unitPrice' => 10000]);

        $draft = $this->post('api/invoice/update/'.$quotation['id'], ['title' => 'Website v2', 'note' => 'Two weeks'])['data']['entity'];
        $this->assertSame('Website v2', $draft['title']);

        $this->post('api/invoice/emit/'.$quotation['id']);
        $bill = $this->post('api/invoice/bill/'.$quotation['id'])['data']['entity'];
        $this->assertSame('bill', $bill['type']);
        $this->assertSame('draft', $bill['status']);
        $this->assertCount(1, $bill['items']);

        $copy = $this->post('api/invoice/duplicate/'.$bill['id'])['data']['entity'];
        $this->assertNotSame($bill['id'], $copy['id']);
        $this->post('api/invoice/update/'.$emitted->getId(), ['title' => 'Too late'], 400);
    }

    public function testStatementsAreImportedAndTransfersLinked(): void
    {
        $this->signIn('anna');
        $main = $this->createBankAccount($this->acme)->setIban('BE68539007547034');
        $savings = $this->createBankAccount($this->acme, 'Savings', '5122');
        $this->em()->flush();

        $this->client->request('POST', '/api/bank-transaction/import?bankAccount='.$main->getId(), files: [
            'file' => new UploadedFile(__DIR__.'/../Fixtures/Bank/camt053.xml', 'camt053.xml', test: true),
        ]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame(2, json_decode($this->client->getResponse()->getContent(), true)['data']['created']);

        $out = $this->createTransaction($main, -5000, label: 'To savings');
        $in = $this->createTransaction($savings, 5000, label: 'From main');
        $this->post('api/bank-transaction/link-transfer', ['transaction' => (string) $out->getId(), 'peer' => (string) $in->getId()]);
        $this->assertTrue($this->get('api/bank-transaction/list?ledger='.$this->acme->getId().'&year=2026&search=savings')['data']['items'][0]['entity']['transfer']);
        $this->post('api/bank-transaction/unlink-transfer/'.$out->getId());

        $this->assertIsArray($this->get('api/bank-transaction/proposals/'.$in->getId())['data']);
        $this->assertArrayHasKey('matched', $this->post('api/bank-transaction/match?ledger='.$this->acme->getId())['data']);
    }

    public function testTheLedgerIsWrittenLetteredReportedAndClosed(): void
    {
        $this->signIn('anna');
        $invoice = $this->emitSale($this->acme, $this->createParty($this->acme));
        $line = $this->createTransaction($this->createBankAccount($this->acme), $invoice->calcRemainingAmount());
        $this->post('api/allocation/create', ['transaction' => (string) $line->getId(), 'invoice' => (string) $invoice->getId()]);

        $entry = $this->post('api/journal-entry/create', [
            'ledger' => (string) $this->acme->getId(),
            'journal' => 'OD',
            'date' => '2026-03-20',
            'label' => 'Bank fees',
            'lines' => [['account' => '627', 'debit' => 500], ['account' => '5121', 'credit' => 500]],
        ])['data']['entity'];
        $this->assertSame(500, $entry['lines'][0]['debit']);
        $this->post('api/journal-entry/create', ['ledger' => (string) $this->acme->getId(), 'journal' => 'OD', 'date' => '2026-03-20', 'label' => 'Unbalanced', 'lines' => [['account' => '627', 'debit' => 500], ['account' => '5121', 'credit' => 400]]], 400);
        $this->post('api/journal-entry/reverse/'.$entry['id'].'?date=2026-03-21');

        $customers = array_values(array_filter(
            array_merge(...array_column(array_column($this->get('api/journal-entry/list?ledger='.$this->acme->getId().'&year=2026&length=50')['data']['items'], 'entity'), 'lines')),
            fn (array $entryLine) => '411' === $entryLine['account']
        ));
        $this->assertCount(2, $customers);
        $letter = $this->post('api/entry-line/letter', ['lines' => array_column($customers, 'id')])['data']['letter'];
        $this->assertNotEmpty($letter);
        $this->post('api/entry-line/unletter/'.$customers[0]['id']);

        $fiscalYear = $this->get('api/fiscal-year/list?ledger='.$this->acme->getId())['data']['items'][0]['entity'];
        $this->assertArrayHasKey('findings', $this->get('api/fiscal-year/check/'.$fiscalYear['id'])['data']);
        $this->post('api/fiscal-year/close/'.$fiscalYear['id'], status: 404);

        $this->assertArrayHasKey('net', $this->get('api/vat-return/show?ledger='.$this->acme->getId().'&from=2026-01-01&to=2026-03-31')['data']);
        $this->assertSame('balance_sheet', $this->get('api/accounting-report/statement?fiscalYear='.$fiscalYear['id'].'&key=balance_sheet')['data']['key']);
        $this->assertNotEmpty($this->get('api/accounting-report/general-ledger?fiscalYear='.$fiscalYear['id'].'&account=411')['data']);
        $this->assertArrayHasKey('csv_entries', $this->get('api/accounting-report/export?fiscalYear='.$fiscalYear['id'])['data']);

        $this->client->request('GET', '/api/accounting-report/export?fiscalYear='.$fiscalYear['id'].'&format=csv_entries');
        $this->assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testAnInvoiceLeavesAndArrivesAsAnElectronicInvoice(): void
    {
        $this->signIn('root');
        $party = $this->createParty($this->acme, 'Globex SA')->setVatNumber('BE0417497106');
        $sale = $this->emitSale($this->acme, $party, 50000);

        $this->client->request('GET', '/api/invoice/electronic/'.$sale->getId());
        $ubl = $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $ubl);
        $this->assertStringContainsString('<Invoice', $ubl);

        $this->client->request('GET', '/api/invoice/electronic/'.$sale->getId().'?format=cii');
        $this->assertStringContainsString('<rsm:CrossIndustryInvoice', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/api/invoice/electronic/'.$sale->getId().'?format=pdf');
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());

        $file = tempnam(sys_get_temp_dir(), 'ubl');
        file_put_contents($file, $ubl);
        $this->client->request('POST', '/api/invoice/receive?ledger='.$this->globex->getId(), files: [
            'file' => new UploadedFile($file, 'invoice.xml', test: true),
        ]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $received = json_decode($this->client->getResponse()->getContent(), true)['data'];

        $this->assertSame([], $received['warnings']);
        $this->assertTrue($received['partyCreated']);
        $this->assertSame('purchase', $received['invoice']['entity']['direction']);
        $this->assertSame('draft', $received['invoice']['entity']['status']);
        $this->assertSame($sale->getNumber(), $received['invoice']['entity']['number']);

        file_put_contents($file, 'hello');
        $this->client->request('POST', '/api/invoice/receive?ledger='.$this->globex->getId(), files: [
            'file' => new UploadedFile($file, 'invoice.txt', test: true),
        ]);
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testLinesComeFromATable(): void
    {
        $this->signIn('anna');
        $draft = $this->post('api/invoice/create', ['ledger' => (string) $this->acme->getId(), 'dateInvoice' => '2026-03-01'])['data'];

        $filled = $this->post('api/invoice-item/import', [
            'invoice' => $draft['entity']['id'],
            'rows' => [['Design', '2', '500,00'], ['Printing', '1', '50']],
            'columns' => ['title' => 0, 'quantity' => 1, 'unitPrice' => 2],
        ])['data'];
        $this->assertCount(2, $filled['entity']['items']);
        $this->assertSame(105000, $filled['entity']['amountNet']);

        $this->post('api/invoice-item/import', [
            'invoice' => $draft['entity']['id'],
            'rows' => [['', '1', '50']],
            'columns' => ['title' => 0, 'unitPrice' => 2],
        ], 400);

        $sent = null;
        $cell = fn (string $text) => ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
        static::getContainer()->set(DocumentReaderClient::class, new DocumentReaderClient(new MockHttpClient(function (string $method, string $url) use (&$sent, $cell) {
            $sent = $url;

            return new MockResponse(json_encode(['ok' => true, 'document' => ['type' => 'doc', 'content' => [
                ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [$cell('Title'), $cell('Price')]], ['type' => 'tableRow', 'content' => [$cell('Design'), $cell('500')]]]],
            ]], 'unmapped' => ['file' => [], 'attachments' => [], 'content' => []]]));
        }), 'http://reader'));

        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, 'PK');
        $this->client->request('POST', '/api/invoice-item/read-table?ledger='.$this->acme->getId(), files: [
            'file' => new UploadedFile($file, 'Lignes été.XLSX', test: true),
        ]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame([[['Title', 'Price'], ['Design', '500']]], json_decode($this->client->getResponse()->getContent(), true)['data']['tables']);
        $this->assertStringContainsString('name=table.xlsx', $sent);
    }

    public function testOnlyAnAdministratorSetsTheBooksUp(): void
    {
        $this->signIn('anna');
        $this->post('api/ledger/update/'.$this->acme->getId(), ['invoicePrefix' => 'ACM'], 404);

        $this->signIn('root');
        $ledger = $this->post('api/ledger/update/'.$this->acme->getId(), ['invoicePrefix' => 'ACM', 'settings' => ['default_vat_rate' => 2100]])['data']['entity'];
        $this->assertSame('ACM', $ledger['invoicePrefix']);
    }

    /**
     * As the firewall's provider knows them (config.yaml): a user it cannot
     * refresh would be signed out at the first request.
     */
    private function signIn(string $identifier): void
    {
        $this->client->loginUser(
            static::getContainer()->get('security.user.provider.concrete.memory')->loadUserByIdentifier($identifier),
            'main'
        );
    }

    private function get(
        string $path,
        int $status = 200
    ): ?array {
        return $this->request('GET', $path, status: $status);
    }

    private function post(
        string $path,
        array $body = [],
        int $status = 200
    ): ?array {
        return $this->request('POST', $path, $body, $status);
    }

    private function request(
        string $method,
        string $path,
        array $body = [],
        int $status = 200
    ): ?array {
        $this->client->request($method, '/'.$path, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body ?: new \stdClass()));
        $response = $this->client->getResponse();

        $this->assertSame($status, $response->getStatusCode(), $method.' '.$path.': '.$response->getContent());

        if (200 !== $status) {
            return null;
        }

        $data = json_decode($response->getContent(), true);
        $this->assertSame([], ApiResponseNormHelper::problems($data), $method.' '.$path);

        return $data;
    }
}
