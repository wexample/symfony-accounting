<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\ParsedBalance;
use Wexample\SymfonyAccounting\Class\ParsedStatement;
use Wexample\SymfonyAccounting\Class\ParsedTransaction;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\BankAccountRepository;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyAccounting\Service\Bank\AllocationService;
use Wexample\SymfonyAccounting\Service\Bank\BankImportService;
use Wexample\SymfonyAccounting\Service\Bank\BankLineAssignmentService;
use Wexample\SymfonyAccounting\Service\Bank\ReconciliationService;

/**
 * A person's account the money of their company also goes through: one
 * account, its lines pointed to the person or to the company.
 */
class SharedBankAccountTest extends AbstractAccountingTestCase
{
    private function statement(): ParsedStatement
    {
        return (new ParsedStatement())
            ->addTransaction(new ParsedTransaction(new DateTimeImmutable('2026-03-02'), 121000, 'VIREMENT DE CLIENT A'))
            ->addTransaction(new ParsedTransaction(new DateTimeImmutable('2026-03-03'), -4550, 'ACHAT CB SUPERMARCHE'))
            ->addBalance(new ParsedBalance(new DateTimeImmutable('2026-03-01'), 10000))
            ->addBalance(new ParsedBalance(new DateTimeImmutable('2026-03-31'), 126450));
    }

    public function testLinesPointedTogetherShareOneShare(): void
    {
        $person = $this->createLedger();
        $company = $this->createLedger();
        $account = $this->createBankAccount($person)->setIban('BE68539007547034');
        $this->em()->flush();
        $this->service(BankImportService::class)->import($account, $this->statement());

        $assignment = $this->service(BankLineAssignmentService::class);
        foreach ($this->service(BankTransactionRepository::class)->findBy(['bankAccount' => $account]) as $line) {
            $assignment->assign($line, $company);
        }
        $this->em()->flush();

        $this->assertCount(2, $this->service(BankAccountRepository::class)->findShares($account));
    }

    public function testLinesArePointedOnceAndCountedTogether(): void
    {
        $person = $this->createLedger();
        $company = $this->createLedger();
        $account = $this->createBankAccount($person)->setIban('BE68539007547034');
        $this->em()->flush();

        $import = $this->service(BankImportService::class);
        $this->assertSame(2, $import->import($account, $this->statement())->count());

        $income = $this->service(BankTransactionRepository::class)->findOneBy(['bankAccount' => $account, 'label' => 'VIREMENT DE CLIENT A']);

        $assignment = $this->service(BankLineAssignmentService::class);
        $assignment->assign($income, $company);
        $this->em()->flush();

        $share = $income->getBankAccount();
        $this->assertNotSame($account, $share);
        $this->assertSame($company, $share->getLedger());
        $this->assertSame($person->getOrganization(), $share->getOwner());
        $this->assertFalse($share->isOwnerShare());
        $this->assertSame([$account, $share], $this->service(BankAccountRepository::class)->findShares($share));

        // The same file again adds nothing, whichever share a line went to.
        $this->assertSame(0, $import->import($account, $this->statement())->count());

        // The bank's balance is the whole account's.
        $this->assertTrue(array_reduce(
            $this->service(ReconciliationService::class)->checkStatements($share),
            static fn (bool $ok, $check) => $ok && $check->isReconciled(),
            true
        ));

        // Explained in the company's books, the line stays there.
        $party = $this->createParty($company);
        $this->service(AllocationService::class)->allocate($income, $this->emitSale($company, $party, 121000, '2026-03-01', 100, 0), 121000);
        $this->em()->flush();
        $this->expectException(AccountingException::class);
        $assignment->release($income);
    }
}
