<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Service\Ledger\LedgerService;
use Wexample\SymfonyAccounting\Service\Ledger\PartyService;
use Wexample\SymfonyOrganization\Entity\Organization;
use Wexample\SymfonyOrganization\Service\OrganizationService;

/**
 * Ledgers and parties are organizations of the directory: one organization,
 * whatever the number of books it appears in.
 */
class OrganizationDirectoryTest extends AbstractAccountingTestCase
{
    public function testAnOrganizationIsOnePartyInEachLedger(): void
    {
        $wexample = $this->createLedger('FR');
        $weegerBe = $this->createLedger('BE');
        $client = $this->service(OrganizationService::class)->create('The Photo Academy SA', $this->country('BE'), vatNumber: 'BE0417497106');

        $parties = $this->service(PartyService::class);
        $inWexample = $parties->findOrCreate($wexample, $client);
        $inWeegerBe = $parties->findOrCreate($weegerBe, $client);

        $this->assertNotSame($inWexample, $inWeegerBe);
        $this->assertSame($client, $inWexample->getOrganization());
        $this->assertSame($client, $inWeegerBe->getOrganization());
        $this->assertSame($inWexample, $parties->findOrCreate($wexample, $client), 'Found again, not created twice.');

        // Each ledger has its own codes and terms…
        $this->assertNotNull($inWexample->getCustomerCode());
        $inWexample->setPaymentTermDays(15);
        $this->assertNull($inWeegerBe->getPaymentTermDays());

        // …and one identity, changed once.
        $client->setName('TPA SA')->setCity('Bruxelles');
        $this->assertSame('TPA SA', $inWexample->getName());
        $this->assertSame('Bruxelles', $inWeegerBe->getCity());
        $this->assertSame(1, $this->em()->getRepository(Organization::class)->count(['vatNumber' => 'BE0417497106']));
    }

    public function testAPartyGainsTheSideItLacked(): void
    {
        $ledger = $this->createLedger();
        $party = $this->createParty($ledger, 'Globex SA', supplier: false);

        $again = $this->service(PartyService::class)->findOrCreate($ledger, $party->getOrganization(), customer: false, supplier: true);

        $this->assertSame($party, $again);
        $this->assertTrue($again->isCustomer());
        $this->assertTrue($again->isSupplier());
        $this->assertNotNull($again->getSupplierCode());
    }

    public function testTheDirectoryFindsAnOrganizationRatherThanAddingIt(): void
    {
        $directory = $this->service(OrganizationService::class);
        $belgium = $this->country('BE');
        $acme = $directory->create('Acme SRL', $belgium, '0123.456.749', 'BE 0123.456.749');
        $this->em()->flush();

        $this->assertSame('0123456749', $acme->getLegalIdentifier(), 'Stored as the country normalizes it.');
        $this->assertSame($acme, $directory->find(vatNumber: 'be0123456749'));
        $this->assertSame($acme, $directory->find($belgium, '0123456749'));
        $this->assertSame($acme, $directory->findOrCreate('ACME', $belgium, 'BE 0123.456.749'));
        $this->assertNull($directory->find($this->country('FR'), '0123456749'), 'An identifier is a country\'s.');
        $this->assertNotSame($acme, $directory->findOrCreate('Other SRL', $belgium));
    }

    public function testBooksOpenOnAnOrganizationAlreadyKnown(): void
    {
        $seller = $this->createLedger();
        $client = $this->createParty($seller, 'Client SA', 'BE', 'BE0417497106');

        $books = $this->service(LedgerService::class)->create($client->getOrganization(), fiscalYearStart: new DateTimeImmutable('2026-01-01'));

        $this->assertSame($client->getOrganization(), $books->getOrganization());
        $this->assertSame('BE', $books->getCountry()->getIsoAlpha2Code());
        $this->assertSame('BE0417497106', $books->getVatNumber());
    }
}
