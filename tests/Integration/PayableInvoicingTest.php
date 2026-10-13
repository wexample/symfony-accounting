<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Service\Bank\Matcher\ProviderPaymentMatcher;
use Wexample\SymfonyAccounting\Service\Invoice\PayableInvoicingService;
use Wexample\SymfonyAccounting\Tests\Fixtures\App\Payable\TestSale;
use Wexample\SymfonyAccounting\Tests\Fixtures\App\Payable\TestSaleLine;
use Wexample\SymfonyAccounting\Tests\Fixtures\App\Payable\TestSaleResolver;
use Wexample\SymfonyGeo\Entity\AbstractAddress;
use Wexample\SymfonyGeo\Entity\AddressSnapshot;
use Wexample\SymfonyMedia\Service\MediaStorage;
use Wexample\SymfonyMoney\Enum\PriceUnit;
use Wexample\SymfonyMoney\Interface\VatRateResolverInterface;
use Wexample\SymfonyPayment\Entity\Payment;
use Wexample\SymfonyPayment\Service\PaymentService;
use Wexample\SymfonyRemoteDocumentReader\Service\DocumentReaderClient;
use Wexample\SymfonyRemotePayment\Class\FakePaymentProvider;

class PayableInvoicingTest extends AbstractAccountingTestCase
{
    private Ledger $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = $this->createLedger('BE', settings: ['default_vat_rate' => 2100]);
        $this->shop->setExternalReference('shop');
        $this->em()->flush();
    }

    private function sale(string $id = 's1'): TestSale
    {
        $sale = new TestSale($id);
        $this->service(TestSaleResolver::class)->sales[$id] = $sale;

        return $sale;
    }

    private function address(string $countryCode): AddressSnapshot
    {
        return AddressSnapshot::fromAddress((new class () extends AbstractAddress {
        })->setPostalAddress('1 Main street')->setPostCode('1000')->setCity('City')->setCountry($this->country($countryCode)));
    }

    private function pay(TestSale $sale): Payment
    {
        $payments = $this->service(PaymentService::class);
        $payment = $payments->getOrCreateForPayable($sale, 'card', $sale->buyerEmail);
        $payments->initiate($payment);
        $payments->handleNotification($this->service(FakePaymentProvider::class)->succeed($payment->getProviderReference()));

        return $payment;
    }

    private function billOf(Payment $payment): ?Invoice
    {
        return $this->service(InvoiceRepository::class)->findBillForPayment($payment);
    }

    public function testAPaidSaleIsBilledToItsBuyer(): void
    {
        $sale = $this->sale();
        $sale->buyerAddress = $this->address('BE');
        $sale->addLine(new TestSaleLine('Chair', 10000, 2, accountNumber: '707100'))->setPriceDiscount(1000, PriceUnit::Percent);
        $sale->addLine(new TestSaleLine('Shipping', 1500));

        $payment = $this->pay($sale);
        $bill = $this->billOf($payment);

        $this->assertNotNull($bill);
        $this->assertTrue($bill->getStatus()->isEmitted());
        $this->assertNotNull($bill->getNumber());
        $this->assertSame('test_sale', $bill->getOrigin());
        $this->assertSame($payment->getAmount(), $bill->calcPriceFinal());

        $items = $bill->getItems()->toArray();
        $this->assertSame('Chair', $items[0]->getTitle());
        $this->assertSame(200, $items[0]->getQuantity(), 'Two units, at the invoice\'s quantity scale.');
        $this->assertSame(2100, $items[0]->getPriceVat());
        $this->assertSame('707100', $items[0]->getAccountNumber());
        $this->assertSame(18000, $items[0]->calcPriceNet());

        $party = $bill->getParty();
        $this->assertSame('Ada Lovelace', $party->getName());
        $this->assertSame('ada@example.com', $party->getEmail());
        $this->assertTrue($party->isIndividual());
        $this->assertSame('BE', $party->getCountry()->getIsoAlpha2Code());

        // Once per payment, and the buyer is found again next time.
        $this->assertSame($bill, $this->service(PayableInvoicingService::class)->invoice($payment));
        $next = $this->sale('s2');
        $next->addLine(new TestSaleLine('Mug', 1000));
        $this->assertSame($party, $this->billOf($this->pay($next))->getParty());
    }

    public function testABusinessAbroadIsChargedNoVat(): void
    {
        $resolver = $this->service(VatRateResolverInterface::class);

        $this->assertSame(0, $resolver->resolveVatRate(2100, false, 'NL', 'NL 123 456 789 B01'));
        $this->assertSame(2100, $resolver->resolveVatRate(2100, false, 'NL', 'NL000000000B00'), 'A number VIES refuses is no business.');
        $this->assertSame(2100, $resolver->resolveVatRate(2100, false, 'DE', 'DE999999999'), 'No verdict, no exemption.');
        $this->assertSame(2100, $resolver->resolveVatRate(2100, false, 'BE', 'BE0987654321'), 'At home, VAT is charged.');
        $this->assertSame(2100, $resolver->resolveVatRate(2100, false, null, null));

        // Checkout charged it so; the bill says the same.
        $sale = $this->sale();
        $sale->buyerCompanyName = 'Dutch BV';
        $sale->buyerVatNumber = 'NL123456789B01';
        $sale->buyerAddress = $this->address('NL');
        $sale->addLine(new TestSaleLine('Consulting', 50000, vat: 0));

        $payment = $this->pay($sale);
        $bill = $this->billOf($payment);

        $this->assertSame(50000, $payment->getAmount());
        $this->assertSame(50000, $bill->calcPriceFinal());
        $this->assertSame('NL123456789B01', $bill->getParty()->getVatNumber());
        $this->assertFalse($bill->getParty()->isIndividual());
    }

    public function testAnUncheckedNumberIsNotKept(): void
    {
        $sale = $this->sale();
        $sale->buyerCompanyName = 'German GmbH';
        $sale->buyerVatNumber = 'DE999999999';
        $sale->buyerAddress = $this->address('DE');
        $sale->addLine(new TestSaleLine('Consulting', 10000));

        $bill = $this->billOf($this->pay($sale));

        $this->assertNull($bill->getParty()->getVatNumber());
        $this->assertSame(12100, $bill->calcPriceFinal());
    }

    public function testARefundIsCredited(): void
    {
        $sale = $this->sale();
        $sale->addLine(new TestSaleLine('Chair', 10000));
        $payment = $this->pay($sale);
        $payments = $this->service(PaymentService::class);

        $payments->recordRefund($payment, 2420, reason: 'Scratched');
        $credits = array_values(array_filter(
            $this->service(InvoiceRepository::class)->findBy(['payment' => $payment]),
            fn (Invoice $invoice) => InvoiceType::CreditNote === $invoice->getType()
        ));
        $this->assertCount(1, $credits);
        $this->assertTrue($credits[0]->getStatus()->isEmitted());
        $this->assertSame(2420, $credits[0]->calcPriceFinal());

        $payments->recordRefund($payment, 12100 - 2420);
        $this->assertCount(3, $this->service(InvoiceRepository::class)->findBy(['payment' => $payment]));
    }

    public function testWithoutSalesLedgerNothingIsBilled(): void
    {
        $this->shop->setExternalReference(null);
        $this->em()->flush();

        $sale = $this->sale();
        $sale->addLine(new TestSaleLine('Chair', 10000));

        $this->assertNull($this->billOf($this->pay($sale)));
    }

    public function testTheProviderLineSettlesTheBill(): void
    {
        $sale = $this->sale();
        $sale->addLine(new TestSaleLine('Chair', 10000));
        $payment = $this->pay($sale);

        $bank = $this->createBankAccount($this->shop, 'Stripe', '5122', provider: 'fake');
        $line = $this->createTransaction($bank, 12100, date: 'today')->setPaymentReference($payment->getProviderReference());

        $proposals = $this->service(ProviderPaymentMatcher::class)->propose($line);

        $this->assertCount(1, $proposals);
        $this->assertSame($this->billOf($payment), $proposals[0]->invoice);
    }

    public function testAProofIsReadForWhoeverConfirms(): void
    {
        $payments = $this->service(PaymentService::class);
        $payment = $payments->create(5000, 'EUR', 'transfer');

        static::getContainer()->set(DocumentReaderClient::class, new DocumentReaderClient(new MockHttpClient(new MockResponse(json_encode([
            'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Transfer of 50,00 EUR — communication '.$payment->getId()],
            ]]]],
            'unmapped' => ['file' => [], 'attachments' => [], 'content' => []],
        ]))), 'http://reader'));

        $file = tempnam(sys_get_temp_dir(), 'proof');
        file_put_contents($file, '%PDF-1.4');
        $payments->attachProof($payment, $this->service(MediaStorage::class)->import($file, 'receipt.pdf'));

        $reading = $payment->getMetadata()['proof_reading'];
        $this->assertTrue($reading['amount_found']);
        $this->assertTrue($reading['reference_found']);
    }
}
