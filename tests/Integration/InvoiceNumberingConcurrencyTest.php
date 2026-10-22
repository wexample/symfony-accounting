<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceSequence;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceNumberingService;

/**
 * Two workers numbering the same series: each has its own entity manager, so its
 * own copy of the sequence row, as two PHP processes would. The in-memory test
 * database cannot block one on the other's lock; what is checked is what the
 * lock relies on — the row read again once locked, and one row per series.
 */
class InvoiceNumberingConcurrencyTest extends AbstractAccountingTestCase
{
    public function testAStaleSequenceIsReadAgainBeforeTakingANumber(): void
    {
        $ledger = $this->createLedger();
        $party = $this->createParty($ledger);
        $first = $this->emitSale($ledger, $party);
        $numbering = $this->service(InvoiceNumberingService::class);

        // This worker now holds the sequence at 1 in memory.
        $this->assertSame(1, $this->em()->getRepository(InvoiceSequence::class)->findOneBy(['ledger' => $ledger])->getLastNumber());

        $other = $this->otherWorker();
        $otherInvoice = $other->find(Invoice::class, $first->getId());
        $taken = (new InvoiceNumberingService($other))->next($otherInvoice);
        $other->flush();

        $mine = $numbering->next($first);

        $this->assertStringEndsWith('-0002', $taken);
        $this->assertStringEndsWith('-0003', $mine, 'The number taken by the other worker is not given twice.');
    }

    public function testASeriesHasOneSequenceRow(): void
    {
        $ledger = $this->createLedger();
        $this->emitSale($ledger, $this->createParty($ledger));

        $other = $this->otherWorker();
        $sequence = $this->em()->getRepository(InvoiceSequence::class)->findOneBy(['ledger' => $ledger]);
        $other->persist(
            (new InvoiceSequence())
                ->setLedger($other->getReference(Ledger::class, $ledger->getId()))
                ->setSeries($sequence->getSeries())
                ->setYear($sequence->getYear())
        );

        $this->expectException(UniqueConstraintViolationException::class);
        $other->flush();
    }

    private function otherWorker(): EntityManager
    {
        $entityManager = $this->em();

        return new EntityManager($entityManager->getConnection(), $entityManager->getConfiguration());
    }
}
