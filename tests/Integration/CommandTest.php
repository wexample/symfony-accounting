<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceWorkflow;

class CommandTest extends AbstractAccountingTestCase
{
    private function runCommand(string $name, array $input): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find($name));
        $tester->execute($input);

        return $tester;
    }

    public function testCommands(): void
    {
        $ledger = $this->createLedger();
        $ledger->setExternalReference('org-42');
        $bank = $this->createBankAccount($ledger);
        $this->emitSale($ledger, $this->createParty($ledger), 10000);
        $this->createTransaction($bank, 12000);

        $file = tempnam(sys_get_temp_dir(), 'camt');
        file_put_contents($file, file_get_contents(__DIR__.'/../Fixtures/Bank/camt053.xml'));
        $import = $this->runCommand('accounting:bank-import', ['bank-account' => (string) $bank->getId(), 'file' => $file]);
        $this->assertStringContainsString('2 line(s) imported', $import->getDisplay());

        $match = $this->runCommand('accounting:match', ['ledger' => 'org-42']);
        $this->assertStringContainsString('1 line(s) matched', $match->getDisplay());

        $vat = $this->runCommand('accounting:vat-return', ['ledger' => 'org-42', 'date' => '2026-03-01']);
        $this->assertStringContainsString('S_DOM_2000', $vat->getDisplay());

        $export = $this->runCommand('accounting:export', ['ledger' => 'org-42', 'fiscal-year' => '2026']);
        $this->assertStringContainsString('csv_entries', $export->getDisplay());

        $check = $this->runCommand('accounting:fiscal-year-close', ['ledger' => 'org-42', 'fiscal-year' => '2026', '--check' => true]);
        $this->assertStringContainsString('fiscal_year.bank_lines_unexplained', $check->getDisplay());

        $checkRun = $this->runCommand('check:run', ['provider' => 'bank', '--format' => 'json']);
        $this->assertStringContainsString('bank.unexplained', $checkRun->getDisplay());
    }

    /**
     * A model gives one document a month: run again in the same month, or for
     * a document dated another day of it, nothing more is made.
     */
    public function testInvoiceRenewMakesOneDocumentPerModelAndMonth(): void
    {
        $ledger = $this->createLedger();
        $factory = $this->service(InvoiceFactory::class);
        $model = $factory->create($ledger, party: $this->createParty($ledger));
        $factory->addItem($model, 'Monthly maintenance', 50000);
        $this->em()->flush();
        $this->service(InvoiceWorkflow::class)->transition($model, InvoiceStatus::Model);
        $this->em()->flush();

        $this->assertStringContainsString('1 document(s) created', $this->runCommand('accounting:invoice-renew', ['date' => '2026-03-01'])->getDisplay());
        $this->assertStringContainsString('0 document(s) created', $this->runCommand('accounting:invoice-renew', ['date' => '2026-03-31'])->getDisplay());
        $this->assertStringContainsString('1 document(s) created', $this->runCommand('accounting:invoice-renew', ['date' => '2026-04-01'])->getDisplay());
        $this->assertCount(2, $this->service(InvoiceRepository::class)->findBy(['model' => $model]));
    }
}
