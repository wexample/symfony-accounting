<?php

namespace Wexample\SymfonyAccounting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wexample\SymfonyAccounting\Service\Bank\Parser\CamtBankStatementParser;
use Wexample\SymfonyAccounting\Service\Bank\Parser\CsvBankStatementParser;
use Wexample\SymfonyAccounting\Service\Bank\Parser\OfxBankStatementParser;
use Wexample\SymfonyAccounting\Service\Bank\Parser\StripeCsvBankStatementParser;

class BankParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/Bank/'.$name);
    }

    public function testCamt(): void
    {
        $parser = new CamtBankStatementParser();
        $content = $this->fixture('camt053.xml');
        $this->assertTrue($parser->supports($content));

        $statement = $parser->parse($content);

        $this->assertSame('BE68539007547034', $statement->iban);
        $this->assertCount(2, $statement->transactions);
        [$credit, $debit] = $statement->transactions;
        $this->assertSame(12100, $credit->amount);
        $this->assertSame('2026-03-10', $credit->date->format('Y-m-d'));
        $this->assertSame('BANKREF-0001', $credit->externalId);
        $this->assertSame('+++090/9337/55493+++', $credit->reference);
        $this->assertSame('CLIENT A', $credit->counterpartyName);
        $this->assertSame('BE71096123456769', $credit->counterpartyIban);
        $this->assertSame(-2050, $debit->amount);
        $this->assertSame('FRAIS DE GESTION MENSUELS', $debit->label);
        $this->assertCount(1, $statement->balances);
        $this->assertSame(110050, $statement->balances[0]->balance);
    }

    public function testOfx(): void
    {
        $parser = new OfxBankStatementParser();
        $content = $this->fixture('statement.ofx');
        $this->assertTrue($parser->supports($content));

        $statement = $parser->parse($content);

        $this->assertCount(2, $statement->transactions);
        $this->assertSame(-4590, $statement->transactions[0]->amount);
        $this->assertSame('PRLV FREE MOBILE FACTURE JANVIER', $statement->transactions[0]->label);
        $this->assertSame('FIT002', $statement->transactions[1]->externalId);
        $this->assertSame(120000, $statement->transactions[1]->amount);
        $this->assertSame('2026-01-12', $statement->transactions[1]->date->format('Y-m-d'));
        $this->assertSame(315410, $statement->balances[0]->balance);
    }

    public function testGenericCsv(): void
    {
        $statement = (new CsvBankStatementParser())->parse($this->fixture('generic.csv'), [
            'date_column' => 'Date',
            'label_columns' => ['Libellé'],
            'debit_column' => 'Débit',
            'credit_column' => 'Crédit',
        ]);

        $this->assertSame([-320, -320, 125000], array_map(fn ($t) => $t->amount, $statement->transactions));
        $this->assertSame('VIR CLIENT C', $statement->transactions[2]->label);
    }

    public function testStripeCsv(): void
    {
        $parser = new StripeCsvBankStatementParser();
        $content = $this->fixture('stripe.csv');
        $this->assertTrue($parser->supports($content));

        $lines = $parser->parse($content)->transactions;

        $this->assertSame(['txn_100:gross', 'txn_100:fee', 'txn_101:gross'], array_map(fn ($t) => $t->externalId, $lines));
        $this->assertSame([10000, -175, -9825], array_map(fn ($t) => $t->amount, $lines));
        $this->assertTrue($lines[1]->metadata['provider_fee']);
    }
}
