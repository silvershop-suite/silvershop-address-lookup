<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Tests;

use SilverShop\AddressLookup\Provider\AddressLookupProvider;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\AddressValidationResult;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;
use SilverStripe\Core\Injector\Injectable;

/**
 * An in-memory, fully-configurable provider for the registry / service / controller / extension tests — no HTTP.
 * Behaviour is set through public properties so a test can register several differently-shaped providers (via
 * Injector named services) and assert the per-country routing. Mirrors newsletter's FakeRemoteProvider.
 */
class FakeLookupProvider implements AddressLookupProvider
{
    use Injectable;

    /**
     * @param array<int, string> $countries supported ISO codes, or ['*'] for every country
     */
    public function __construct(
        public string $key = 'fake',
        public array $countries = ['NL'],
        public bool $configured = true,
        public string $mode = AddressLookupProvider::MODE_POSTCODE_NUMBER,
        public ?string $category = null,
        public ?ResolvedAddress $resolved = null,
        public bool $throws = false,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return 'Fake (' . $this->key . ')';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function supportsCountry(string $countryCode): bool
    {
        return in_array('*', $this->countries, true)
            || in_array(strtoupper($countryCode), $this->countries, true);
    }

    public function lookupMode(string $countryCode): string
    {
        return $this->supportsCountry($countryCode) ? $this->mode : self::MODE_NONE;
    }

    public function lookup(AddressQuery $query): ?ResolvedAddress
    {
        if ($this->throws) {
            throw new \RuntimeException('provider fault');
        }

        return $this->resolved;
    }

    public function suggest(string $term, string $countryCode): array
    {
        return [];
    }

    public function validate(ResolvedAddress $address): AddressValidationResult
    {
        return AddressValidationResult::unknown();
    }

    public function consentCategory(): ?string
    {
        return $this->category;
    }
}
