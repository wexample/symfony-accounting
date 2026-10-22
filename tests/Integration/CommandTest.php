<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

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
}
