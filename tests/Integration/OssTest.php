<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\VatKind;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Vat\OssService;
use Wexample\SymfonyAccounting\Service\Vat\VatReturnService;

class OssTest extends AbstractAccountingTestCase
{
    private function sell(
        Ledger $ledger,
        Party $party,
        int $price,
        string $date,
        bool $goods = true
    ): Invoice {
        $factory = $this->service(InvoiceFactory::class);
        $invoice = $factory->create($ledger, party: $party, dateInvoice: new DateTimeImmutable($date));
        $factory->addItem($invoice, $goods ? 'Lamp' : 'Advice', $price, goods: $goods);
        $this->em()->flush();

        return $this->service(InvoiceEmissionService::class)->emit($invoice);
    }

    public function testDistanceSalesMoveToTheCustomersCountryPastTheThreshold(): void
    {
        $ledger = $this->createLedger();
        $german = $this->createParty($ledger, 'Hans Müller', 'DE');
        $french = $this->createParty($ledger, 'Jeanne Dupont', 'FR');

        $first = $this->sell($ledger, $german, 500000, '2026-01-10');
        $second = $this->sell($ledger, $german, 600000, '2026-02-10');
        $advice = $this->sell($ledger, $french, 100000, '2026-03-10', goods: false);

        // Under the threshold, the seller's VAT; the sale passing it is taxed in Germany.
        $this->assertSame(VatKind::Domestic, $first->getItems()->first()->getVatKind());
        $this->assertSame(VatKind::Oss, $second->getItems()->first()->getVatKind());
        $this->assertSame(1900, $second->getItems()->first()->getPriceVat());
        $this->assertSame(600000 + 114000, $second->calcPriceFinal());
        // Services other than electronic ones stay taxed where the seller is.
        $this->assertSame(VatKind::Domestic, $advice->getItems()->first()->getVatKind());

        $from = new DateTimeImmutable('2026-01-01');
        $to = new DateTimeImmutable('2026-03-31');
        $oss = $this->service(OssService::class);

        $this->assertSame([['country' => 'DE', 'rate' => 1900, 'base' => 600000, 'vat' => 114000]], $oss->computeReturn($ledger, $from, $to));
        $this->assertSame(1100000, $oss->getDistanceSales($ledger, 2026));
        $this->assertSame("country;rate;base;vat\nDE;19.00;6000.00;1140.00\n", $oss->exportCsv($ledger, $oss->computeReturn($ledger, $from, $to)));

        // The national return leaves the OSS sale out.
        $this->assertSame(100000 + 20000, $this->service(VatReturnService::class)->compute($ledger, $from, $to)->getDue());

        $oss->settle($ledger, $from, $to);
        $this->expectException(AccountingException::class);
        $oss->settle($ledger, $from, $to);
    }

    public function testARegisteredLedgerUsesTheOssFromTheFirstSaleAndElectronicServicesCount(): void
    {
        $ledger = $this->createLedger(settings: ['default_vat_rate' => 2000, 'oss_registered' => true, 'oss_electronic_services' => true]);
        $dutch = $this->createParty($ledger, 'Pieter de Vries', 'NL');

        $subscription = $this->sell($ledger, $dutch, 1000, '2026-01-10', goods: false);

        $this->assertSame('S_OSS_NL_2100', $subscription->getItems()->first()->getVatCode());
        $this->assertSame(1210, $subscription->calcPriceFinal());
    }

    public function testABusinessCustomerIsNoDistanceSale(): void
    {
        $ledger = $this->createLedger(settings: ['default_vat_rate' => 2000, 'oss_registered' => true]);
        $company = $this->createParty($ledger, 'Dutch BV', 'NL', 'NL123456789B01');

        $this->assertSame(VatKind::IntraEuGoods, $this->sell($ledger, $company, 1000, '2026-01-10')->getItems()->first()->getVatKind());
    }
}
