<?php

namespace Wexample\SymfonyAccounting\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Event\InvoiceEmittedEvent;
use Wexample\SymfonyAccounting\Event\InvoiceStatusChangedEvent;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceAccountingService;

/**
 * Books documents when emitted, reverses them when an emitted one is canceled.
 * A ledger can turn it off with the setting `auto_post_invoices: false`.
 */
class InvoiceAccountingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly InvoiceAccountingService $accountingService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InvoiceEmittedEvent::class => 'onEmitted',
            InvoiceStatusChangedEvent::class => 'onStatusChanged',
        ];
    }

    public function onEmitted(InvoiceEmittedEvent $event): void
    {
        if ($event->invoice->getLedger()->getSetting('auto_post_invoices', true)) {
            $this->accountingService->book($event->invoice);
        }
    }

    public function onStatusChanged(InvoiceStatusChangedEvent $event): void
    {
        if (InvoiceStatus::Canceled === $event->invoice->getStatus() && $event->previousStatus->isEmitted()) {
            $this->accountingService->reverse($event->invoice);
        }
    }
}
