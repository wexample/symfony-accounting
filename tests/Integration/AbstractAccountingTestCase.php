<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceAccountingService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Ledger\LedgerService;
use Wexample\SymfonyAccounting\Service\Ledger\PartyService;
use Wexample\SymfonyGeo\Entity\Country;

abstract class AbstractAccountingTestCase extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = $this->em();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    /**
     * The country row of an ISO code, created on first use.
     */
    protected function country(string $code): Country
    {
        $repository = $this->em()->getRepository(Country::class);
        $country = $repository->findOneBy(['isoAlpha2Code' => $code]);

        if (! $country) {
            $country = (new Country())
                ->setIsoAlpha2Code($code)
                ->setIsoAlpha3Code($code.'X')
                ->setIsoNumericCode(str_pad((string) (ord($code[0]) * 3 + ord($code[1])), 3, '0', STR_PAD_LEFT))
                ->setName($code);
            $this->em()->persist($country);
            $this->em()->flush();
        }

        return $country;
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function service(string $id): object
    {
        return static::getContainer()->get($id);
    }

    protected function createLedger(
        string $countryCode = 'BE',
        string $fiscalYearStart = '2026-01-01',
        array $settings = ['default_vat_rate' => 2000],
    ): Ledger {
        $ledger = $this->service(LedgerService::class)->create(
            'Test Company',
            $this->country($countryCode),
            fiscalYearStart: new DateTimeImmutable($fiscalYearStart)
        );
        $ledger->setSettings($settings)->setVatNumber($countryCode.'0123456749');
        $this->em()->flush();

        return $ledger;
    }

    protected function createParty(
        Ledger $ledger,
        string $name = 'Client A',
        ?string $countryCode = null,
        ?string $vatNumber = null,
        bool $customer = true,
        bool $supplier = false,
    ): Party {
        $party = $this->service(PartyService::class)->create($ledger, $name, $customer, $supplier, $countryCode ? $this->country($countryCode) : null);
        $party->setVatNumber($vatNumber);
        $this->em()->flush();

        return $party;
    }

    /**
     * A sale bill of one item, emitted.
     */
    protected function emitSale(
        Ledger $ledger,
        Party $party,
        int $unitPrice = 10000,
        string $date = '2026-03-01',
        int $quantity = 100,
        ?int $vatRate = null,
    ): Invoice {
        $factory = $this->service(InvoiceFactory::class);
        $invoice = $factory->create($ledger, party: $party, dateInvoice: new DateTimeImmutable($date));
        $factory->addItem($invoice, 'Service', $unitPrice, $quantity, $vatRate);
        $this->em()->flush();

        return $this->service(InvoiceEmissionService::class)->emit($invoice);
    }

    protected function createBankAccount(
        Ledger $ledger,
        string $label = 'Main bank',
        string $accountNumber = '5121',
        ?string $provider = null,
        ?string $journalCode = null,
    ): BankAccount {
        $bankAccount = (new BankAccount())
            ->setLedger($ledger)
            ->setLabel($label)
            ->setAccountNumber($accountNumber)
            ->setJournalCode($journalCode)
            ->setProvider($provider);

        $this->em()->persist($bankAccount);
        $this->em()->flush();

        return $bankAccount;
    }

    protected function createTransaction(
        BankAccount $bankAccount,
        int $amount,
        string $date = '2026-03-15',
        string $label = 'Bank line',
        ?string $reference = null,
    ): BankTransaction {
        $transaction = (new BankTransaction())
            ->setBankAccount($bankAccount)
            ->setAmount($amount)
            ->setDate(new DateTimeImmutable($date))
            ->setLabel($label)
            ->setReference($reference)
            ->refreshFingerprint();

        $this->em()->persist($transaction);
        $this->em()->flush();

        return $transaction;
    }

    protected function entryOf(Invoice $invoice): ?JournalEntry
    {
        return $this->service(InvoiceAccountingService::class)->findEntry($invoice);
    }

    /**
     * Lines of an entry as "account:debit/credit" strings, for compact assertions.
     *
     * @return list<string>
     */
    protected function describeLines(JournalEntry $entry): array
    {
        $lines = [];

        foreach ($entry->getLines() as $line) {
            $lines[] = $line->getAccount()->getNumber().':'.$line->getDebit().'/'.$line->getCredit();
        }

        return $lines;
    }
}
