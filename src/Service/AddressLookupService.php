<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Service;

use SilverShop\AddressLookup\Consent\ConsentResolver;
use SilverShop\AddressLookup\Provider\AddressLookupProvider;
use SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\AddressValidationResult;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;
use SilverStripe\Core\Injector\Injectable;
use Throwable;

/**
 * Orchestrates a lookup: resolve the per-country provider from the registry, check its consent gate, call the
 * provider, and hand back the normalised value object. Every provider call is wrapped so a provider fault degrades
 * to a soft miss (null / empty) — the checkout form must stay usable whatever a third-party API does.
 */
class AddressLookupService
{
    use Injectable;

    private readonly AddressLookupProviderRegistry $registry;

    private readonly ConsentResolver $consent;

    public function __construct(
        ?AddressLookupProviderRegistry $registry = null,
        ?ConsentResolver $consent = null,
    ) {
        $this->registry = $registry ?? AddressLookupProviderRegistry::create();
        $this->consent = $consent ?? ConsentResolver::create();
    }

    /**
     * The provider that will handle a country, or null for the manual form. Exposed so the frontend extension can
     * advertise the per-country mode without duplicating the routing.
     */
    public function providerForCountry(string $countryCode): ?AddressLookupProvider
    {
        $provider = $this->registry->forCountry($countryCode);
        if ($provider === null || !$this->consent->allows($provider)) {
            return null;
        }

        return $provider;
    }

    /**
     * The frontend lookup mode for a country ('postcode_number' | 'autocomplete' | 'none').
     */
    public function modeForCountry(string $countryCode): string
    {
        $provider = $this->providerForCountry($countryCode);

        return $provider ? $provider->lookupMode($countryCode) : AddressLookupProvider::MODE_NONE;
    }

    public function lookup(AddressQuery $query): ?ResolvedAddress
    {
        $provider = $this->providerForCountry($query->countryCode);
        if ($provider === null) {
            return null;
        }

        try {
            return $provider->lookup($query);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<int, ResolvedAddress>
     */
    public function suggest(string $term, string $countryCode): array
    {
        $provider = $this->providerForCountry($countryCode);
        if ($provider === null || trim($term) === '') {
            return [];
        }

        try {
            return $provider->suggest($term, $countryCode);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function validate(ResolvedAddress $address): AddressValidationResult
    {
        $provider = $this->providerForCountry($address->countryCode);
        if ($provider === null) {
            return AddressValidationResult::unknown();
        }

        try {
            return $provider->validate($address);
        } catch (Throwable $e) {
            return AddressValidationResult::unknown();
        }
    }
}
