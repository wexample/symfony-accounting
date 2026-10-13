<?php

namespace Wexample\SymfonyAccounting\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Wexample\SymfonyAccounting\Service\Document\PaymentProofReader;
use Wexample\SymfonyPayment\Event\PaymentProofAttachedEvent;
use Wexample\SymfonyRemoteDocumentReader\Exception\DocumentReaderException;

/**
 * A proof arrives: what it shows is written under the payment's metadata
 * `proof_reading`, for whoever confirms the money.
 */
class PaymentProofSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PaymentProofReader $proofReader,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PaymentProofAttachedEvent::class => 'onProofAttached',
        ];
    }

    public function onProofAttached(PaymentProofAttachedEvent $event): void
    {
        try {
            $reading = $this->proofReader->read($event->payment);
        } catch (DocumentReaderException $exception) {
            // The proof stays; only its reading failed, which a person does instead.
            $reading = ['error' => $exception->getMessage()];
        }

        if (null === $reading) {
            return;
        }

        $event->payment->setMetadata(['proof_reading' => $reading] + $event->payment->getMetadata());
        $this->entityManager->flush();
    }
}
