<?php

namespace Wexample\SymfonyAccounting\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Wexample\SymfonyAccounting\Service\Document\PeppolInvoiceService;
use Wexample\SymfonyRemotePeppol\Event\DocumentReceivedEvent;
use Wexample\SymfonyRemotePeppol\Event\TransmissionUpdatedEvent;

/**
 * What the access point reports, written into the books: a sent document's
 * status, a received invoice as a purchase draft. A received invoice that cannot
 * be read throws, so it stays with the access point rather than being lost.
 */
class PeppolSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PeppolInvoiceService $peppolService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TransmissionUpdatedEvent::class => 'onTransmissionUpdated',
            DocumentReceivedEvent::class => 'onDocumentReceived',
        ];
    }

    public function onTransmissionUpdated(TransmissionUpdatedEvent $event): void
    {
        if ($this->peppolService->update($event->transmission)) {
            $this->entityManager->flush();
        }
    }

    public function onDocumentReceived(DocumentReceivedEvent $event): void
    {
        if ($this->peppolService->receive($event->document)) {
            $this->entityManager->flush();
        }
    }
}
