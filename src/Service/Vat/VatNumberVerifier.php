<?php

namespace Wexample\SymfonyAccounting\Service\Vat;

use Psr\Log\LoggerInterface;
use Wexample\SymfonyRemoteVies\Exception\ViesException;
use Wexample\SymfonyRemoteVies\Service\ViesClient;

/**
 * Whether a buyer's VAT number is one VIES confirms: what lets a seller charge
 * no VAT to a business from another EU country.
 */
class VatNumberVerifier
{
    public function __construct(
        private readonly ViesClient $viesClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A VIES giving no verdict confirms nothing: the buyer is charged VAT, which
     * the seller never owes back, rather than none on a number nobody checked.
     */
    public function isConfirmed(string $vatNumber): bool
    {
        try {
            return $this->viesClient->check($vatNumber)->valid;
        } catch (ViesException $exception) {
            $this->logger->warning('VIES gave no verdict on {vat_number}: charged as unconfirmed.', [
                'vat_number' => $vatNumber,
                'error' => $exception->errorCode,
            ]);

            return false;
        }
    }
}
