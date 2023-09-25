<?php

namespace Wexample\SymfonyAccounting\Class;

use App\Entity\AccountingTransaction;
use App\Entity\Invoice;

class AccountingCollection
{
    /**
     * @var array<Invoice>
     */
    private array $invoices = [];
    /**
     * @var array<AccountingTransaction>
     */
    private array $transactions = [];

    private ?string $fingerPrint;

    public function add(
        Invoice|AccountingTransaction $entity,
    ): void {
        if ($entity instanceof Invoice) {
            $this->addInvoice($entity);
        } else {
            $this->addTransaction($entity);
        }
    }

    public function addInvoice(
        Invoice $invoice,
        bool $update = true
    ): void {
        $id = $invoice->getId();

        if (!isset($this->invoices[$id])) {
            $this->invoices[$id] = $invoice;

            if ($update) {
                $this->updateFingerPrint();
            }
        }
    }

    public function updateFingerPrint(): string
    {
        $keys = [];

        foreach ($this->invoices as $invoice) {
            $keys[] = 'I'.$invoice->getId();
        }

        foreach ($this->transactions as $transaction) {
            $keys[] = 'T'.$transaction->getId();
        }

        sort($keys);

        $this->fingerPrint = implode('-', $keys);

        return $this->getFingerPrint();
    }

    public function getFingerPrint(): string
    {
        return $this->fingerPrint;
    }

    public function addTransaction(
        AccountingTransaction $transaction,
        bool $updateFingerPrint = true
    ): void {
        $id = $transaction->getId();

        if (!isset($this->transactions[$id])) {
            $this->transactions[$id] = $transaction;

            if ($updateFingerPrint) {
                $this->updateFingerPrint();
            }
        }
    }

    public function contains(Invoice|AccountingTransaction $entity): bool
    {
        $container = $entity instanceof Invoice ? $this->invoices : $this->transactions;

        foreach ($container as $containerEntity) {
            if ($containerEntity->getId() === $entity->getId()) {
                return true;
            }
        }

        return false;
    }

    public function getInvoices(): array
    {
        return $this->invoices;
    }

    public function getTransactions(): array
    {
        return $this->transactions;
    }
}
