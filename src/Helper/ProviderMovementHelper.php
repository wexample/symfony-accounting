<?php

namespace Wexample\SymfonyAccounting\Helper;

/**
 * The external ids given to the lines of a payment provider's balance, shared by
 * every way of importing them (API, CSV) so that they deduplicate each other.
 */
class ProviderMovementHelper
{
    public const string METADATA_FEE = 'provider_fee';

    public static function grossId(string $movementId): string
    {
        return $movementId.':gross';
    }

    public static function feeId(string $movementId): string
    {
        return $movementId.':fee';
    }
}
