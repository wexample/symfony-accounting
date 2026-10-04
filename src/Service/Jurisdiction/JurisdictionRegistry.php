<?php

namespace Wexample\SymfonyAccounting\Service\Jurisdiction;

use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Interface\JurisdictionInterface;

/**
 * Finds the jurisdiction of a country; the default one when no package covers it.
 */
class JurisdictionRegistry
{
    /** @var array<string, JurisdictionInterface> */
    private array $byCountry = [];

    private JurisdictionInterface $default;

    /**
     * @param iterable<JurisdictionInterface> $jurisdictions
     */
    public function __construct(iterable $jurisdictions = [])
    {
        $this->default = new DefaultJurisdiction();

        foreach ($jurisdictions as $jurisdiction) {
            if (null === $jurisdiction->getCountryCode()) {
                $this->default = $jurisdiction;
            } else {
                $this->byCountry[strtoupper($jurisdiction->getCountryCode())] = $jurisdiction;
            }
        }
    }

    public function get(?string $countryCode): JurisdictionInterface
    {
        return $this->byCountry[strtoupper((string) $countryCode)] ?? $this->default;
    }

    public function forLedger(Ledger $ledger): JurisdictionInterface
    {
        return $this->get($ledger->getCountryCode());
    }

    public function has(string $countryCode): bool
    {
        return isset($this->byCountry[strtoupper($countryCode)]);
    }

    /**
     * @return array<string, JurisdictionInterface>
     */
    public function all(): array
    {
        return $this->byCountry;
    }
}
