<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Tests\Fixtures\App\Access\FirmGroup;
use Wexample\SymfonyAccounting\Tests\Fixtures\App\Access\FirmGroupResolver;
use Wexample\SymfonyApi\Helper\ApiResponseNormHelper;

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
