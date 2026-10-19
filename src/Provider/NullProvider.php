<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Provider;

use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\AddressValidationResult;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;
use SilverStripe\Core\Injector\Injectable;

/**
 * A no-op provider: supports no country, resolves nothing, and is never "configured" so the registry never returns
 * it. Keeps callers null-safe where a non-null {@see AddressLookupProvider} is wanted (a default binding / a seam in
 * tests) without any network behaviour.
 */
class NullProvider implements AddressLookupProvider
{
    use Injectable;

    public function getKey(): string
    {
        return 'null';
    }

    public function getName(): string
    {
        return 'None';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function supportsCountry(string $countryCode): bool
    {
        return false;
    }

    public function lookupMode(string $countryCode): string
    {
        return self::MODE_NONE;
    }

    public function lookup(AddressQuery $query): ?ResolvedAddress
    {
        return null;
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
        return null;
    }
}
