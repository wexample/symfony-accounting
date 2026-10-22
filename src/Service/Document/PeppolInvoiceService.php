<?php

namespace Wexample\SymfonyAccounting\Service\Document;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Wexample\SymfonyAccounting\Class\ElectronicInvoiceImportResult;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\InvoiceTransmission;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\InvoiceTransmissionRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyRemotePeppol\Class\PeppolDocument;
use Wexample\SymfonyRemotePeppol\Class\PeppolParticipant;
use Wexample\SymfonyRemotePeppol\Class\PeppolTransmission;
use Wexample\SymfonyRemotePeppol\Class\ReceivedDocument;
use Wexample\SymfonyRemotePeppol\Exception\AccessPointException;
use Wexample\SymfonyRemotePeppol\Service\AccessPointRegistry;

/**
 * The books on Peppol: an emitted sale sent as UBL to its client, the news of
 * what was sent kept on the document, a received invoice made a purchase draft
 * in the ledger it is addressed to. A ledger's address is the one its documents
 * carry (ElectronicInvoiceDataBuilder::endpoint()): enterprise number, SIRET,
 * else VAT number. Does not flush.
 */
class PeppolInvoiceService
{
    public function __construct(
        private readonly AccessPointRegistry $accessPointRegistry,
        private readonly ElectronicInvoiceDataBuilder $dataBuilder,
        private readonly UblInvoiceBuilder $ublBuilder,
        private readonly ElectronicInvoiceReceiver $receiver,
        private readonly InvoiceTransmissionRepository $transmissionRepository,
        private readonly LedgerRepository $ledgerRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->accessPointRegistry->findSender();
    }

    public function participantOfLedger(Ledger $ledger): ?PeppolParticipant
    {
        return $this->participant($this->dataBuilder->endpoint(
            ['legalIdentifier' => $ledger->getLegalIdentifier(), 'vatNumber' => $ledger->getVatNumber()],
            (string) $ledger->getCountry()?->getIsoAlpha2Code(),
        ));
    }

    /**
     * Whether the document can go over Peppol: an emitted sale, a sender, both
     * parties addressable, and the client listed as accepting it. Without a
     * directory to ask, the access point is left to answer.
     */
    public function isReachable(Invoice $invoice): bool
    {
        if (! $this->isAvailable() || InvoiceDirection::Sale !== $invoice->getDirection() || ! $this->dataBuilder->supports($invoice)) {
            return false;
        }

        $data = $this->dataBuilder->build($invoice);
        $receiver = $this->participant($data['buyer']['endpoint']);

        if (null === $this->participant($data['seller']['endpoint']) || null === $receiver) {
            return false;
        }

        $directory = $this->accessPointRegistry->findDirectory();

        if (null === $directory) {
            return true;
        }

        $documentType = $data['credit'] ? PeppolDocument::BILLING_CREDIT_NOTE : PeppolDocument::BILLING_INVOICE;

        return in_array($documentType, $directory->findDocumentTypes($receiver) ?? [], true);
    }

    public function send(Invoice $invoice): InvoiceTransmission
    {
        $sender = $this->accessPointRegistry->findSender() ?? throw new AccountingException('No Peppol access point to send through.');

        if (InvoiceDirection::Sale !== $invoice->getDirection()) {
            throw new AccountingException('Only a sale is sent over Peppol.');
        }

        $data = $this->dataBuilder->build($invoice);
        $from = $this->participant($data['seller']['endpoint']) ?? throw new AccountingException('The ledger has no Peppol address: an enterprise number, a SIRET or a VAT number.');
        $to = $this->participant($data['buyer']['endpoint']) ?? throw new AccountingException('The client has no Peppol address: an enterprise number, a SIRET or a VAT number.');

        try {
            $sent = $sender->send(PeppolDocument::billing($from, $to, $this->ublBuilder->build($invoice)));
        } catch (AccessPointException $exception) {
            throw new AccountingException('The access point refused the document: '.$exception->getMessage(), previous: $exception);
        }

        $transmission = (new InvoiceTransmission())
            ->setInvoice($invoice)
            ->setProvider($sent->provider)
            ->setReference($sent->reference)
            ->setReceiver((string) $to)
            ->setDateSent($sent->at);
        $this->apply($transmission, $sent);
        $this->entityManager->persist($transmission);

        return $transmission;
    }

    /**
     * The access point's news about a document we sent; null when it is none of ours.
     */
    public function update(PeppolTransmission $news): ?InvoiceTransmission
    {
        $transmission = $this->transmissionRepository->findOneBy(['provider' => $news->provider, 'reference' => $news->reference]);

        if ($transmission) {
            $this->apply($transmission, $news);
        }

        return $transmission;
    }

    /**
     * A received invoice or credit note made a purchase draft in the ledger it is
     * addressed to; null for another kind of document. What a person should check
     * is kept in the draft's metadata (`import_warnings`).
     */
    public function receive(ReceivedDocument $document): ?ElectronicInvoiceImportResult
    {
        if (! $document->isBilling()) {
            return null;
        }

        $ledger = $this->findLedger($document->receiver) ?? throw new AccountingException(sprintf('No ledger answers to the Peppol address %s.', $document->receiver));
        $result = $this->receiver->receive($ledger, $document->content);
        $invoice = $result->invoice->setOrigin('peppol');

        if ($result->warnings) {
            $invoice->setMetadata($invoice->getMetadata() + ['import_warnings' => $result->warnings]);
        }

        return $result;
    }

    public function findLedger(PeppolParticipant $participant): ?Ledger
    {
        foreach ($this->ledgerRepository->findAll() as $ledger) {
            if ($this->participantOfLedger($ledger)?->equals($participant)) {
                return $ledger;
            }
        }

        return null;
    }

    private function apply(
        InvoiceTransmission $transmission,
        PeppolTransmission $news
    ): void {
        $transmission
            ->setStatus($news->status)
            ->setMessage($news->message)
            ->setDateUpdated(new DateTimeImmutable());
    }

    /**
     * @param array{scheme: string, id: string}|null $endpoint
     */
    private function participant(?array $endpoint): ?PeppolParticipant
    {
        return $endpoint ? new PeppolParticipant($endpoint['scheme'], $endpoint['id']) : null;
    }
}
