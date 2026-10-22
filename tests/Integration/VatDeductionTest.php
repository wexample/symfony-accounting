<?php

namespace Wexample\SymfonyAccounting\Tests\Integration;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Vat\VatDeductionRegularizationService;
use Wexample\SymfonyAccounting\Service\Vat\VatReturnService;

class VatDeductionTest extends AbstractAccountingTestCase
{
    private function buy(
        Ledger $ledger,
        string $account,
        int $price,
        string $date = '2026-02-01'
    ): void {
        $factory = $this->service(InvoiceFactory::class);
        $purchase = $factory->create($ledger, direction: InvoiceDirection::Purchase, party: $this->createParty($ledger, 'Supplier '.$account, customer: false, supplier: true), dateInvoice: new DateTimeImmutable($date))
            ->setNumber('P-'.uniqid());
        $factory->addItem($purchase, 'Purchase', $price, accountNumber: $account);
        $this->em()->flush();
        $this->service(InvoiceEmissionService::class)->emit($purchase);
    }

    public function testTheCoefficientAndTheAdmissionCutWhatIsDeducted(): void
    {
        $ledger = $this->createLedger(settings: [
            'default_vat_rate' => 2000,
            'vat_deduction_rate' => 8000,
            // Receptions: no VAT deducted at all.
            'vat_admission_rates' => ['6257' => 0],
        ]);

        $this->buy($ledger, '6064', 10000);
        $this->buy($ledger, '6257', 5000);

        $return = $this->service(VatReturnService::class)->compute($ledger, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-03-31'));

        // 2 000 of VAT at 80 % on supplies, nothing on receptions.
        $this->assertSame(1600, $return->getDeductible());

        $regularization = $this->service(VatDeductionRegularizationService::class);
        $this->assertSame(['eligible' => 2000, 'deducted' => 1600, 'target' => 1800, 'difference' => 200], $regularization->compute($ledger, 2026, 9000));

        $entry = $regularization->regularize($ledger, 2026, 9000);
        $this->assertSame(200, $entry->getLines()->first()->getDebit());
        $this->assertSame([2026 => 9000, 2027 => 9000], $ledger->getSetting('vat_deduction_rates'));

        $this->expectException(AccountingException::class);
        $regularization->regularize($ledger, 2026, 9000);
    }

    public function testFullDeductionIsUntouched(): void
    {
        $ledger = $this->createLedger();
        $this->buy($ledger, '6064', 10000);

        $this->assertSame(2000, $this->service(VatReturnService::class)->compute($ledger, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-03-31'))->getDeductible());
    }
}
