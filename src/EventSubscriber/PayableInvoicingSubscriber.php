<?php

namespace Wexample\SymfonyAccounting\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Wexample\SymfonyAccounting\Service\Invoice\PayableInvoicingService;
use Wexample\SymfonyPayment\Event\PaymentRefundedEvent;
use Wexample\SymfonyPayment\Event\PaymentSucceededEvent;

/**
 * A paid payable gets its bill, a refund its credit note (PayableInvoicingService).
 */
class PayableInvoicingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PayableInvoicingService $invoicingService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PaymentSucceededEvent::class => 'onSucceeded',
            PaymentRefundedEvent::class => 'onRefunded',
        ];
    }

    public function onSucceeded(PaymentSucceededEvent $event): void
    {
        $this->invoicingService->invoice($event->payment);
    }

    public function onRefunded(PaymentRefundedEvent $event): void
    {
        $this->invoicingService->credit($event->payment, $event->refund);
    }
}
