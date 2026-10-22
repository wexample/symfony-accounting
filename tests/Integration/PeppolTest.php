<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\InvoiceTransmissionRepository;
use Wexample\SymfonyAccounting\Service\Document\PeppolInvoiceService;
use Wexample\SymfonyAccounting\Service\Document\UblInvoiceBuilder;
use Wexample\SymfonyAccounting\Service\Ledger\LedgerService;
use Wexample\SymfonyRemotePeppol\Class\FakeAccessPoint;
use Wexample\SymfonyRemotePeppol\Class\PeppolParticipant;
use Wexample\SymfonyRemotePeppol\Enum\TransmissionStatus;
use Wexample\SymfonyRemotePeppol\Event\TransmissionUpdatedEvent;
use Wexample\SymfonyRemotePeppol\Service\InboxCollector;

class PeppolTest extends AbstractAccountingTestCase
{
    private function accessPoint(): FakeAccessPoint
    {
        return $this->service(FakeAccessPoint::class);
    }

    private function sale(): \Wexample\SymfonyAccounting\Entity\Invoice
    {
        $ledger = $this->createLedger();
        $ledger->getOrganization()->setLegalIdentifier('0123.456.749');
        $party = $this->createParty($ledger, 'Client SA', 'BE', 'BE0417497106');
        $party->getOrganization()->setLegalIdentifier('0417.497.106');

        return $this->emitSale($ledger, $party, 50000);
    }

    public function testAReachableClientGetsItsInvoiceOverPeppol(): void
    {
        $sale = $this->sale();
        $service = $this->service(PeppolInvoiceService::class);

        $this->assertFalse($service->isReachable($sale));

        $this->accessPoint()->register(PeppolParticipant::fromString('0208:0417497106'));
        $this->assertTrue($service->isReachable($sale));

        $transmission = $service->send($sale);
        $this->em()->flush();

        $sent = $this->accessPoint()->sent[0];
        $this->assertSame('0208:0123456749', (string) $sent->sender);
        $this->assertSame('0208:0417497106', (string) $sent->receiver);
        $this->assertSame($this->service(UblInvoiceBuilder::class)->build($sale), $sent->content);
        $this->assertSame(TransmissionStatus::Queued, $transmission->getStatus());

        $this->service(EventDispatcherInterface::class)->dispatch(new TransmissionUpdatedEvent($this->accessPoint()->deliver($transmission->getReference())));

        $this->assertSame(TransmissionStatus::Delivered, $this->service(InvoiceTransmissionRepository::class)->findForInvoice($sale)[0]->getStatus());
    }

    public function testAClientNotOnPeppolIsRefused(): void
    {
        $this->expectException(AccountingException::class);

        $this->service(PeppolInvoiceService::class)->send($this->sale());
    }

    public function testAReceivedInvoiceLandsInTheLedgerItIsAddressedTo(): void
    {
        $sale = $this->sale();
        $ubl = $this->service(UblInvoiceBuilder::class)->build($sale);

        // The client keeps its own books: they open on the organization the
        // seller already knows it by.
        $buyer = $this->service(LedgerService::class)->create($sale->getParty()->getOrganization(), fiscalYearStart: new \DateTimeImmutable('2026-01-01'));
        $buyer->setSettings(['default_vat_rate' => 2000])->getOrganization()->setLegalIdentifier('0417497106');
        $this->em()->flush();

        $this->accessPoint()->receive(PeppolParticipant::fromString('0208:0123456749'), PeppolParticipant::fromString('0208:0417497106'), $ubl);
        $this->assertSame(1, $this->service(InboxCollector::class)->collect());

        $purchase = $this->em()->getRepository(\Wexample\SymfonyAccounting\Entity\Invoice::class)->findOneBy(['ledger' => $buyer]);
        $this->assertSame($sale->getNumber(), $purchase->getNumber());
        $this->assertSame('peppol', $purchase->getOrigin());
        $this->assertSame($sale->calcPriceFinal(), $purchase->calcPriceFinal());
        $this->assertSame([], $this->accessPoint()->fetch());
    }

    public function testADocumentForNoLedgerStaysInTheInbox(): void
    {
        $ubl = $this->service(UblInvoiceBuilder::class)->build($this->sale());
        $this->accessPoint()->receive(PeppolParticipant::fromString('0208:0123456749'), PeppolParticipant::fromString('0208:0999999999'), $ubl);

        try {
            $this->service(InboxCollector::class)->collect();
            $this->fail('No exception.');
        } catch (AccountingException $exception) {
            $this->assertStringContainsString('0208:0999999999', $exception->getMessage());
        }

        $this->assertCount(1, $this->accessPoint()->fetch());
    }
}
